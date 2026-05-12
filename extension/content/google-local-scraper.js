/**
 * Minerador Google Local — content script (MV3)
 * Fluxo: webhp?udm=1 → busca → #search → clique em cada resultado → painel (.immersive-container ou #local-place-viewer)
 */
(function () {
  "use strict";

  const STORAGE_KEY = "mineradorState";
  const WEBHP =
    "https://www.google.com/webhp?udm=1&hl=pt-BR&gl=br";

  /** Ordem: preferir o painel imersivo; fallback para a variante do viewer local. */
  const PANEL_ROOT_SELECTORS = [".immersive-container", "#local-place-viewer"];

  const DEBUG = true;
  function dlog(...args) {
    if (DEBUG) console.log("[minerador]", ...args);
  }
  function describeEl(el) {
    if (!el) return "null";
    try {
      const tag = (el.tagName || "?").toLowerCase();
      const id = el.id ? `#${el.id}` : "";
      const clsRaw = typeof el.className === "string" ? el.className : "";
      const cls = clsRaw
        ? `.${clsRaw.trim().split(/\s+/).slice(0, 3).join(".")}`
        : "";
      const txt = (el.textContent || "")
        .trim()
        .replace(/\s+/g, " ")
        .slice(0, 80);
      const href = el.getAttribute?.("href") || "";
      const aria = el.getAttribute?.("aria-label") || "";
      const jsname = el.getAttribute?.("jsname") || "";
      const role = el.getAttribute?.("role") || "";
      return `<${tag}${id}${cls}> role="${role}" aria="${aria}" jsname="${jsname}" href="${href}" text="${txt}"`;
    } catch (_) {
      return "<el?>";
    }
  }

  function previewPayload(p) {
    try {
      const clone = { ...p };
      if (clone.aba_sobre_html) {
        clone.aba_sobre_html = `[${(clone.aba_sobre_html || "").length} chars]`;
      }
      return JSON.stringify(clone, null, 2).slice(0, 500);
    } catch (_) {
      return "(payload)";
    }
  }

  function previewBatchPayload(body) {
    try {
      const clone = { ...body };
      if (Array.isArray(clone.leads)) {
        clone.leads = clone.leads.map((L) => {
          const c = { ...L };
          if (c.aba_sobre_html) {
            c.aba_sobre_html = `[${(c.aba_sobre_html || "").length} chars]`;
          }
          return c;
        });
      }
      const s = JSON.stringify(clone, null, 2);
      return s.length > 4000 ? s.slice(0, 4000) + "\n…(truncado)" : s;
    } catch (_) {
      return "(batch)";
    }
  }

  let running = false;
  let lastCaptchaAlert = 0;
  let myTabAllowed = null;
  let lastTabGateCheck = 0;
  const processedHrefsThisPage = new Set();
  let stepResolve = null;
  let lastStepModeOverlayHintMs = 0;

  function tabGate() {
    return new Promise((resolve) => {
      try {
        chrome.runtime.sendMessage({ type: "MINERADOR_TAB_GATE" }, (resp) => {
          if (chrome.runtime.lastError) {
            resolve(false);
            return;
          }
          resolve(!!(resp && resp.allowed));
        });
      } catch (_) {
        resolve(false);
      }
    });
  }

  async function isThisTabAllowed() {
    const now = Date.now();
    if (myTabAllowed !== null && now - lastTabGateCheck < 1500) {
      return myTabAllowed;
    }
    const allowed = await tabGate();
    myTabAllowed = allowed;
    lastTabGateCheck = now;
    return allowed;
  }

  function sleep(ms) {
    return new Promise((r) => setTimeout(r, ms));
  }

  function randomDelay(min = 400, max = 1200) {
    return sleep(min + Math.random() * (max - min));
  }

  async function getState() {
    const { [STORAGE_KEY]: s } = await chrome.storage.local.get(STORAGE_KEY);
    return s || {};
  }

  async function patchState(partial) {
    const cur = await getState();
    const next = { ...cur, ...partial };
    await chrome.storage.local.set({ [STORAGE_KEY]: next });
    return next;
  }

  async function reportStatus(text, error = null) {
    await patchState({ statusText: text, lastError: error });
  }

  function isPausedSync(state) {
    return !!state.paused;
  }

  function detectCaptcha() {
    const html = document.documentElement.innerHTML;
    const low = document.body?.innerText?.toLowerCase() || "";
    if (document.querySelector('iframe[src*="recaptcha"]')) return true;
    if (document.querySelector(".g-recaptcha")) return true;
    if (html.includes("google.com/recaptcha")) return true;
    if (low.includes("unusual traffic")) return true;
    if (low.includes("não sou um robô") || low.includes("nao sou um robo"))
      return true;
    if (low.includes("antes de continuar")) return true;
    if (document.querySelector("#captchaimg")) return true;
    return false;
  }

  function throttleCaptchaAlert() {
    const now = Date.now();
    if (now - lastCaptchaAlert < 12000) return;
    lastCaptchaAlert = now;
    chrome.runtime.sendMessage({ type: "MINERADOR_CAPTCHA" });
  }

  async function waitUntil(fn, timeoutMs = 20000, step = 200) {
    const t0 = Date.now();
    while (Date.now() - t0 < timeoutMs) {
      const s = await getState();
      if (!s.active) return false;
      if (isPausedSync(s)) {
        await sleep(400);
        continue;
      }
      if (detectCaptcha()) {
        throttleCaptchaAlert();
        await reportStatus("Captcha / verificação detectada. Resolva e use Continuar.");
        await sleep(800);
        continue;
      }
      try {
        if (fn()) return true;
      } catch (_) {}
      await sleep(step);
    }
    return false;
  }

  function isGoogleHost() {
    const h = location.hostname;
    return h === "www.google.com" || h === "www.google.com.br";
  }

  function isAllowedScrapeUrl() {
    if (!isGoogleHost()) return false;
    const p = location.pathname || "";
    if (p.startsWith("/search")) return true;
    if (p.includes("webhp")) return true;
    return false;
  }

  function ensureOverlay() {
    if (document.getElementById("mineradorOverlay")) return;
    const o = document.createElement("div");
    o.id = "mineradorOverlay";
    o.style.cssText =
      "position:fixed;right:16px;bottom:16px;z-index:2147483647;background:#111;color:#fff;padding:12px 14px;border-radius:10px;box-shadow:0 6px 24px rgba(0,0,0,.4);font:13px system-ui,sans-serif;max-width:380px;line-height:1.35;";
    o.innerHTML =
      '<div style="font-weight:600;margin-bottom:6px;">Minerador — passo a passo</div>' +
      '<div id="mo_desc" style="margin-bottom:8px;white-space:pre-wrap;word-break:break-word;">aguardando…</div>' +
      '<div id="mo_ctx" style="opacity:.7;font-size:11px;margin-bottom:8px;"></div>' +
      '<div style="display:flex;gap:6px;">' +
      '<button id="mo_next" type="button" style="flex:1;padding:6px 8px;border:0;border-radius:6px;background:#2563eb;color:#fff;cursor:pointer;font-weight:600;">Próximo</button>' +
      '<button id="mo_pause" type="button" style="padding:6px 8px;border:0;border-radius:6px;background:#374151;color:#fff;cursor:pointer;">Pausar</button>' +
      '<button id="mo_stop" type="button" style="padding:6px 8px;border:0;border-radius:6px;background:#7f1d1d;color:#fff;cursor:pointer;">Encerrar</button>' +
      "</div>";
    (document.body || document.documentElement).appendChild(o);
    o.querySelector("#mo_next").addEventListener("click", () => {
      if (stepResolve) {
        const r = stepResolve;
        stepResolve = null;
        r();
      }
    });
    o.querySelector("#mo_pause").addEventListener("click", () => {
      patchState({ paused: true, statusText: "Pausado pelo usuário (overlay)." });
    });
    o.querySelector("#mo_stop").addEventListener("click", () => {
      if (stepResolve) {
        const r = stepResolve;
        stepResolve = null;
        r();
      }
      patchState({
        active: false,
        paused: false,
        statusText: "Encerrado pelo usuário (overlay).",
      });
    });
  }

  function hideOverlay() {
    const o = document.getElementById("mineradorOverlay");
    if (o) o.remove();
  }

  async function stepGate(desc, ctx) {
    const state = await getState();
    dlog("STEP:", desc, ctx || "");
    if (!state.stepMode) {
      if (
        DEBUG &&
        Date.now() - lastStepModeOverlayHintMs > 60000
      ) {
        lastStepModeOverlayHintMs = Date.now();
        dlog(
          "Overlay de passo a passo desligado: ative \"Modo passo a passo\" no popup da extensão para pausar e ver o JSON no overlay antes de cada POST."
        );
      }
      return;
    }
    ensureOverlay();
    const o = document.getElementById("mineradorOverlay");
    if (!o) return;
    o.querySelector("#mo_desc").textContent = desc || "(sem descrição)";
    o.querySelector("#mo_ctx").textContent = ctx || "";
    try {
      o.querySelector("#mo_next").focus();
    } catch (_) {}
    await new Promise((resolve) => {
      stepResolve = resolve;
    });
  }

  function isWebhpUdm() {
    return (
      location.pathname.includes("webhp") &&
      new URLSearchParams(location.search).get("udm") === "1"
    );
  }

  function hasSearchResultsLayout() {
    return !!document.getElementById("search");
  }

  function getQueryFromUrl() {
    try {
      return new URLSearchParams(location.search).get("q") || "";
    } catch (_) {
      return "";
    }
  }

  function currentPageNumber() {
    const start = Number(new URLSearchParams(location.search).get("start") || "0");
    return Math.floor(start / 10) + 1;
  }

  function normalizeHref(href) {
    try {
      const u = new URL(href, location.origin);
      u.hash = "";
      return u.toString();
    } catch (_) {
      return href;
    }
  }

  function isVisible(el) {
    if (!el || !(el instanceof Element)) return false;
    return !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length);
  }

  /** Id estável pv-/g/… do card local (sobe pelo DOM, sem classes obfuscadas). */
  function resolvePvIdFromLocalCard(el) {
    if (!el || typeof el.closest !== "function") return "";
    const node = el.closest('div[role="button"][id^="pv-"]');
    if (!node) return "";
    const id = node.getAttribute("id") || "";
    return id.startsWith("pv-") ? id : "";
  }

  function hrefLooksUsable(href) {
    const h = (href || "").trim();
    if (!h || h === "#") return false;
    if (/^javascript:/i.test(h)) return false;
    return true;
  }

  function getResultTargetKey(el) {
    if (!el) return "";
    const pvId = resolvePvIdFromLocalCard(el);
    if (pvId) return "google:local:" + pvId;

    if (el.tagName === "A") {
      const rawHref = el.getAttribute("href") || el.href || "";
      if (hrefLooksUsable(rawHref)) return normalizeHref(rawHref);
      const vedA = el.getAttribute("data-ved") || "";
      if (vedA) return "google:ved:" + vedA;
      return "";
    }

    const pid = el.getAttribute("id") || "";
    if (pid.startsWith("pv-")) return "google:local:" + pid;

    const ved = el.getAttribute("data-ved") || "";
    if (ved) return "google:ved:" + ved;

    return "";
  }

  /** Fallback antigo: só quando não há cards `pv-` na SERP (layout legado). */
  function collectLegacySerpAnchorLinks(search) {
    const map = new Map();
    const legacyLinks = Array.from(
      search.querySelectorAll("a.rllt__link.a-no-hover-decoration, a.rllt__link")
    ).filter((a) => isVisible(a) && !a.closest('[role="navigation"]'));
    for (const a of legacyLinks) {
      if (!a || !isVisible(a)) continue;
      const key = getResultTargetKey(a);
      if (!key) continue;
      if (!map.has(key)) map.set(key, a);
    }
    return Array.from(map.values());
  }

  function getResultAnchors() {
    const search = document.getElementById("search");
    if (!search) return [];
    const map = new Map();

    function tryAdd(el) {
      if (!el || !isVisible(el) || el.closest('[role="navigation"]')) return;
      const key = getResultTargetKey(el);
      if (!key) return;
      if (!map.has(key)) map.set(key, el);
    }

    Array.from(search.querySelectorAll('div[role="button"][id^="pv-"]'))
      .filter((btn) => {
        if (!isVisible(btn) || btn.closest('[role="navigation"]')) return false;
        return !!btn.querySelector('[role="heading"]');
      })
      .forEach((btn) => tryAdd(btn));

    if (map.size > 0) return Array.from(map.values());

    return collectLegacySerpAnchorLinks(search);
  }

  async function nativeClick(el) {
    if (!el) return;
    dlog("nativeClick", describeEl(el));
    try {
      el.scrollIntoView({ block: "center" });
    } catch (_) {}
    await sleep(300);
    try {
      el.focus({ preventScroll: true });
    } catch (_) {}
    const r = el.getBoundingClientRect();
    const x = r.left + r.width / 2;
    const y = r.top + r.height / 2;
    const baseOpts = {
      bubbles: true,
      cancelable: true,
      composed: true,
      clientX: x,
      clientY: y,
      view: window,
      button: 0,
    };
    try {
      el.dispatchEvent(new PointerEvent("pointerover", baseOpts));
      el.dispatchEvent(new PointerEvent("pointerenter", baseOpts));
      el.dispatchEvent(new MouseEvent("mouseover", baseOpts));
      el.dispatchEvent(new MouseEvent("mouseenter", baseOpts));
      el.dispatchEvent(new PointerEvent("pointerdown", baseOpts));
      el.dispatchEvent(new MouseEvent("mousedown", baseOpts));
      el.dispatchEvent(new PointerEvent("pointerup", baseOpts));
      el.dispatchEvent(new MouseEvent("mouseup", baseOpts));
      el.dispatchEvent(new MouseEvent("click", baseOpts));
    } catch (_) {}
    try {
      el.click();
    } catch (_) {}
  }

  function getImmersiveRoot() {
    for (const sel of PANEL_ROOT_SELECTORS) {
      const el = document.querySelector(sel);
      if (el) return el;
    }
    return null;
  }

  function isImmersiveOpen() {
    const r = getImmersiveRoot();
    if (!r || !isVisible(r)) return false;
    try {
      if (r.getAttribute("aria-hidden") === "true") return false;
    } catch (_) {}
    try {
      const st = window.getComputedStyle(r);
      if (st.display === "none" || st.visibility === "hidden") return false;
      const op = parseFloat(st.opacity);
      if (!Number.isNaN(op) && op < 0.03) return false;
    } catch (_) {}
    try {
      const br = r.getBoundingClientRect();
      if (br.width < 4 || br.height < 4) return false;
    } catch (_) {}
    return true;
  }

  function isTabLike(el) {
    if (!el) return false;
    if (el.getAttribute("role") === "tab") return true;
    if (
      el.tagName === "BUTTON" &&
      (el.hasAttribute("aria-selected") || el.getAttribute("role") === "tab")
    )
      return true;
    return false;
  }

  function isSafeTabCandidate(el) {
    if (!el) return false;
    if (el.tagName === "A") {
      const href = el.getAttribute("href") || "";
      if (/support\.google\.com|policies\.google\.com|accounts\.google\.com/i.test(href)) {
        return false;
      }
      if (/^https?:\/\//i.test(href)) return false;
    }
    return true;
  }

  function findTab(root, names) {
    const scope = root || getImmersiveRoot() || document;
    const candidates = Array.from(
      scope.querySelectorAll('[role="tab"], button[aria-selected]')
    );
    for (const el of candidates) {
      if (!isVisible(el)) continue;
      if (!isTabLike(el)) continue;
      if (!isSafeTabCandidate(el)) continue;
      const t = (el.textContent || "").trim().toLowerCase();
      const al = (el.getAttribute("aria-label") || "").trim().toLowerCase();
      if (names.some((n) => t === n || al === n)) return el;
    }
    return null;
  }

  async function clickVisaoGeral(root) {
    const tab = findTab(root, ["visão geral", "visao geral", "overview"]);
    dlog("clickVisaoGeral ->", describeEl(tab));
    if (tab) {
      await stepGate(
        "Clicar na aba 'Visão geral': " + describeEl(tab),
        "imersivo aberto"
      );
      await nativeClick(tab);
      await sleep(350);
    } else {
      dlog("clickVisaoGeral: aba não encontrada (possivelmente já ativa)");
    }
  }

  async function clickSobreTab(root) {
    const tab = findTab(root, ["sobre", "about"]);
    dlog("clickSobreTab ->", describeEl(tab));
    if (tab) {
      await stepGate(
        "Clicar na aba 'Sobre': " + describeEl(tab),
        "antes de extrair innerHTML"
      );
      await nativeClick(tab);
      await sleep(400);
    } else {
      dlog("clickSobreTab: aba não encontrada");
    }
  }

  function parseAddressFromText(text) {
    const raw = (text || "").replace(/^Endereço:\s*/i, "").trim();
    let cidade = "";
    let uf = "";
    let cep = "";
    const mCep = raw.match(/(\d{5}-?\d{3})\s*$/);
    if (mCep) cep = mCep[1].replace(/(\d{5})(\d{3})/, "$1-$2");
    const mUf = raw.match(/,\s*([^,]+)\s*-\s*([A-Z]{2})\s*,?\s*(\d{5}-?\d{3})?\s*$/i);
    if (mUf) {
      cidade = mUf[1].trim();
      uf = mUf[2].toUpperCase();
    }
    return { endereco_completo: raw, cidade, uf, cep };
  }

  function parseAddressFromAria(aria) {
    return parseAddressFromText(aria || "");
  }

  function textLooksLikeBrazilStreetAddress(text) {
    const t = (text || "").replace(/\s+/g, " ").trim();
    if (t.length < 12) return false;
    if (/\d{5}-?\d{3}\b/.test(t)) return true;
    if (/,/.test(t) && /\s-\s[A-Z]{2}\b/.test(t)) return true;
    if (/,/.test(t) && /[Rr]\.?\s|Av\.|Rua\s|Rod\./.test(t)) return true;
    return false;
  }

  function hrefLooksLikeMapsPlaceLink(href) {
    if (!href) return false;
    const h = href.toLowerCase();
    if (!h.includes("maps.google") && !h.includes("google.com/maps")) return false;
    return h.includes("ftid=") || h.includes("pvq=");
  }

  /**
   * Endereço a partir de link Maps cujo texto visível é o endereço (painel local).
   * Preferência: href com ftid=/pvq=; classe zfFVc só fallback frágil.
   */
  function tryFillAddressFromMapsLink(root, addrParsed) {
    if (!root) return addrParsed;
    if (String(addrParsed?.endereco_completo || "").trim()) return addrParsed;

    const anchors = Array.from(
      root.querySelectorAll('a[href*="maps.google"], a[href*="google.com/maps"]')
    );
    for (const a of anchors) {
      const href = a.getAttribute("href") || "";
      if (!hrefLooksLikeMapsPlaceLink(href)) continue;
      const linkText = (a.innerText || a.textContent || "").replace(/\s+/g, " ").trim();
      if (!linkText || /^site$/i.test(linkText)) continue;
      if (!textLooksLikeBrazilStreetAddress(linkText)) continue;
      return parseAddressFromText(linkText);
    }

    const fragile = root.querySelector("a.zfFVc[href*='maps.google']");
    if (fragile) {
      const linkText = (fragile.innerText || fragile.textContent || "").replace(/\s+/g, " ").trim();
      if (textLooksLikeBrazilStreetAddress(linkText)) {
        return parseAddressFromText(linkText);
      }
    }
    return addrParsed;
  }

  function parsePhoneFromLigarAria(aria) {
    const al = (aria || "").trim();
    if (!al) return "";
    const m =
      al.match(/ligar\s+para\s+(.+)/i) ||
      al.match(/call\s+(.+)/i) ||
      al.match(/phone\s*:\s*(.+)/i);
    return m ? m[1].replace(/\s+/g, " ").trim() : "";
  }

  /** Segunda passagem: data-phone-number e aria "Ligar para …" (sem depender de classe Od1FEc). */
  function tryAppendPhonesFallback(root, telefoneSeen, pushUniquePhone) {
    if (!root) return;
    root.querySelectorAll("a[data-phone-number], [data-phone-number]").forEach((el) => {
      pushUniquePhone(el.getAttribute("data-phone-number"));
    });
    root.querySelectorAll('a[role="button"][aria-label*="Ligar" i]').forEach((el) => {
      const fromAria = parsePhoneFromLigarAria(el.getAttribute("aria-label") || "");
      if (fromAria) pushUniquePhone(fromAria);
    });
  }

  /** Depois da aba Sobre: preencher só lacunas (DOM pode ter atualizado). */
  function applyPanelFallbacks(base) {
    const root = getImmersiveRoot();
    if (!root || !base) return base;

    let addrParsed = {
      endereco_completo: base.endereco_completo || "",
      cidade: base.cidade || "",
      uf: base.uf || "",
      cep: base.cep || "",
    };
    if (!String(addrParsed.endereco_completo || "").trim()) {
      addrParsed = tryFillAddressFromMapsLink(root, addrParsed);
    }

    const telefones = Array.isArray(base.telefones) ? [...base.telefones] : [];
    const telefoneSeen = new Set();
    for (const t of telefones) {
      const d = String(t || "").replace(/\D/g, "");
      if (d) telefoneSeen.add(d);
    }
    function pushUniquePhone(raw) {
      const s = String(raw || "").trim();
      if (!s) return;
      const digits = s.replace(/\D/g, "");
      if (!digits || telefoneSeen.has(digits)) return;
      telefoneSeen.add(digits);
      telefones.push(s);
    }
    if (!telefones.length) {
      tryAppendPhonesFallback(root, telefoneSeen, pushUniquePhone);
    }

    return {
      ...base,
      endereco_completo: addrParsed.endereco_completo,
      cidade: addrParsed.cidade,
      uf: addrParsed.uf,
      cep: addrParsed.cep,
      telefones,
    };
  }

  /** Raiz do card na SERP: botão `pv-…` (sem classes obfuscadas / jsname). */
  function getSerpCardRoot(anchorEl) {
    if (!anchorEl) return null;
    if (anchorEl.matches?.('div[role="button"][id^="pv-"]')) return anchorEl;
    return anchorEl.closest('div[role="button"][id^="pv-"]') || null;
  }

  /** pv- quando existir; senão sobe até um ancestral que contenha o heading (layout legado com `<a>`). */
  function getSerpExtractionScope(anchorEl) {
    const card = getSerpCardRoot(anchorEl);
    if (card) return card;
    if (!anchorEl) return null;
    let el = anchorEl;
    for (let d = 0; d < 12 && el && el !== document.documentElement; d++) {
      if (typeof el.querySelector === "function" && el.querySelector('[role="heading"]'))
        return el;
      el = el.parentElement;
    }
    return anchorEl;
  }

  function serpLineLooksLikeRatingLine(text) {
    const t = (text || "").replace(/\s+/g, " ").trim();
    if (!t) return true;
    if (/\d+[.,]\d+\s+de\s+5/i.test(t)) return true;
    if (/\d+\s+avaliações/i.test(t) || /\d+\s+avaliacoes/i.test(t)) return true;
    if (/críticas|criticas/i.test(t) && /\d/.test(t)) return true;
    return false;
  }

  /**
   * Nome, nota, total de avaliações e categoria a partir do subtree do card (#search),
   * só com role/aria-label/texto (sem classes tokenizadas do Google).
   */
  function extractFromSerpRow(anchorEl) {
    const scope = getSerpExtractionScope(anchorEl);
    if (!scope) return { nome: "", nota: null, total_avaliacoes: null, categoria: "" };

    const heading = scope.querySelector('[role="heading"]');
    let nome = "";
    if (heading) nome = (heading.textContent || "").replace(/\s+/g, " ").trim();

    let nota = null;
    let total_avaliacoes = null;
    const candidates = scope.querySelectorAll(
      '[role="img"][aria-label*="classificação" i], [role="img"][aria-label*="Classificacao" i], ' +
        '[role="img"][aria-label*="Avaliação" i], [role="img"][aria-label*="Avaliacao" i]'
    );
    for (const el of candidates) {
      const al = el.getAttribute("aria-label") || "";
      const p = parseRatingFromAriaLabel(al);
      if (p.nota != null) nota = p.nota;
      if (p.total_avaliacoes != null) total_avaliacoes = p.total_avaliacoes;
      if (nota != null && total_avaliacoes != null) break;
    }

    let categoria = "";
    if (nome && heading) {
      const nomeLower = nome.toLowerCase();
      let sib = heading.nextElementSibling;
      while (sib) {
        const full = (sib.textContent || "").replace(/\s+/g, " ").trim();
        if (full && !serpLineLooksLikeRatingLine(full) && full.toLowerCase() !== nomeLower) {
          const parts = full.split(/\s*·\s*/).map((p) => p.trim()).filter(Boolean);
          if (parts.length >= 2) {
            const last = parts[parts.length - 1];
            if (last && last.toLowerCase() !== nomeLower) categoria = last;
          } else {
            categoria = full;
          }
          if (categoria) break;
        }
        sib = sib.nextElementSibling;
      }
      if (!categoria && heading.parentElement) {
        const outer = heading.parentElement.nextElementSibling;
        if (outer) {
          const full = (outer.textContent || "").replace(/\s+/g, " ").trim();
          if (full && !serpLineLooksLikeRatingLine(full)) {
            const parts = full.split(/\s*·\s*/).map((p) => p.trim()).filter(Boolean);
            if (parts.length >= 2) {
              categoria = parts[parts.length - 1].trim();
            } else if (full.toLowerCase() !== nomeLower) {
              categoria = full;
            }
          }
        }
      }
    }

    return { nome, nota, total_avaliacoes, categoria };
  }

  /** Sobrescreve nome/nota/total_avaliacoes/categoria com dados da lista SERP quando disponíveis. */
  function applySerpPreviewToBase(serp, base) {
    const out = { ...base };
    if (serp.nome) out.nome = serp.nome;
    if (serp.nota != null) out.nota = serp.nota;
    if (serp.total_avaliacoes != null) out.total_avaliacoes = serp.total_avaliacoes;
    if (serp.categoria) out.categoria = serp.categoria;
    return out;
  }

  function parseRatingFromAriaLabel(al) {
    let nota = null;
    let total_avaliacoes = null;
    if (!al) return { nota, total_avaliacoes };
    const mCrit = al.match(/(\d+)\s*(críticas|criticas)/i);
    if (mCrit) total_avaliacoes = parseInt(mCrit[1], 10);
    if (total_avaliacoes == null) {
      const mAv = al.match(/(\d+)\s+avaliações(?:\s+de\s+usuários)?/i) ||
        al.match(/(\d+)\s+avaliacoes(?:\s+de\s+usuarios)?/i);
      if (mAv) total_avaliacoes = parseInt(mAv[1], 10);
    }
    const mNota = al.match(/(\d+[.,]\d+)\s+de\s+5/i) ||
      al.match(/(\d+[.,]\d+)\s*(?:em|de)\s+5/i);
    if (mNota) nota = parseFloat(mNota[1].replace(",", "."));
    return { nota, total_avaliacoes };
  }

  function fillFromLuAttributeList(scope, state) {
    const lu = scope.querySelector('[data-attrid="kc:/local:lu attribute list"]');
    if (!lu) return;
    for (const node of lu.querySelectorAll("[aria-label]")) {
      const al = node.getAttribute("aria-label") || "";
      const p = parseRatingFromAriaLabel(al);
      if (state.nota == null && p.nota != null) state.nota = p.nota;
      if (state.total_avaliacoes == null && p.total_avaliacoes != null) {
        state.total_avaliacoes = p.total_avaliacoes;
      }
    }
    if (state.nota == null || state.total_avaliacoes == null) {
      const blob = (lu.textContent || "").replace(/\s+/g, " ").trim();
      const p2 = parseRatingFromAriaLabel(blob);
      if (state.nota == null && p2.nota != null) state.nota = p2.nota;
      if (state.total_avaliacoes == null && p2.total_avaliacoes != null) {
        state.total_avaliacoes = p2.total_avaliacoes;
      }
    }
    if (state.total_avaliacoes == null) {
      const mParen = (lu.textContent || "").match(/\((\d+)\)/);
      if (mParen) state.total_avaliacoes = parseInt(mParen[1], 10);
    }
  }

  function extractCategoryFromLuBlock(lu, nomeLower) {
    if (!lu) return "";
    const rejectLine = (t) => {
      const tx = t.trim();
      if (tx.length < 3 || tx.length > 220) return true;
      if (nomeLower && tx.toLowerCase() === nomeLower) return true;
      if (/^\d+[.,]\d+$/.test(tx)) return true;
      if (/^\(\d+\)$/.test(tx)) return true;
      if (/\d+[.,]\d+\s+de\s+5/i.test(tx)) return true;
      if (/\d+\s+avaliações/i.test(tx) || /\d+\s+avaliacoes/i.test(tx)) return true;
      if (/críticas|criticas/i.test(tx) && /\d/.test(tx)) return true;
      return false;
    };
    for (const el of lu.querySelectorAll("span")) {
      if (el.closest('[role="img"]')) continue;
      const tx = (el.textContent || "").replace(/\s+/g, " ").trim();
      if (rejectLine(tx)) continue;
      if (el.querySelector("span")) continue;
      return tx;
    }
    return "";
  }

  function extractAddressTextFromKcLocation(root) {
    const wrap = root.querySelector('[data-attrid="kc:/location/location:address"]');
    if (!wrap) return "";
    const rawBlock = (wrap.innerText || wrap.textContent || "")
      .replace(/\s+/g, " ")
      .trim();
    const stripped = rawBlock.replace(/^.*?\bEndereço\b\s*:?\s*/i, "").trim();
    if (stripped.length >= 8) return stripped;
    let best = "";
    for (const el of wrap.querySelectorAll("span")) {
      const t = (el.textContent || "").replace(/\s+/g, " ").trim();
      if (!t || /^Endereço/i.test(t)) continue;
      if (t.length > best.length) best = t;
    }
    return best;
  }

  function extractRatingReviewsCategory(root) {
    const title = root.querySelector('h2[data-attrid="title"]');
    const block = title?.parentElement || root;
    const scopes = [];
    if (block && block !== root) scopes.push(block);
    scopes.push(root);

    let nota = null;
    let total_avaliacoes = null;
    let categoria = "";

    for (const scope of scopes) {
      if (total_avaliacoes != null) break;
      const crit = scope.querySelector(
        '[aria-label*="críticas" i], [aria-label*="criticas" i]'
      );
      if (crit) {
        const al = crit.getAttribute("aria-label") || "";
        const m = al.match(/(\d+)\s*(críticas|criticas)/i);
        if (m) total_avaliacoes = parseInt(m[1], 10);
      }
    }
    for (const scope of scopes) {
      if (total_avaliacoes != null) break;
      const nodes = scope.querySelectorAll(
        '[aria-label*="avaliações" i], [aria-label*="avaliacoes" i]'
      );
      for (const node of nodes) {
        const al = node.getAttribute("aria-label") || "";
        const p = parseRatingFromAriaLabel(al);
        if (p.total_avaliacoes != null) {
          total_avaliacoes = p.total_avaliacoes;
          break;
        }
      }
    }

    const imgSelectors =
      '[role="img"][aria-label*="Classificação" i], [role="img"][aria-label*="Classificacao" i], ' +
      '[role="img"][aria-label*="Avaliação" i], [role="img"][aria-label*="Avaliacao" i]';
    for (const scope of scopes) {
      if (nota != null && total_avaliacoes != null) break;
      const imgs = scope.querySelectorAll(imgSelectors);
      for (const img of imgs) {
        const al = img.getAttribute("aria-label") || "";
        const p = parseRatingFromAriaLabel(al);
        if (nota == null && p.nota != null) nota = p.nota;
        if (total_avaliacoes == null && p.total_avaliacoes != null) {
          total_avaliacoes = p.total_avaliacoes;
        }
        if (nota != null && total_avaliacoes != null) break;
      }
    }

    const tmp = { nota, total_avaliacoes };
    for (const scope of scopes) {
      fillFromLuAttributeList(scope, tmp);
    }
    nota = tmp.nota;
    total_avaliacoes = tmp.total_avaliacoes;

    const nomeEl =
      root.querySelector('h2[data-attrid="title"]') ||
      root.querySelector("h1") ||
      root.querySelector("h2");
    const nomeLower = (nomeEl?.textContent || "").trim().toLowerCase();
    for (const scope of scopes) {
      const lu = scope.querySelector('[data-attrid="kc:/local:lu attribute list"]');
      const catLu = extractCategoryFromLuBlock(lu, nomeLower);
      if (catLu) {
        categoria = catLu;
        break;
      }
    }
    if (!categoria) {
      for (const scope of scopes) {
        const catSpan = Array.from(scope.querySelectorAll("span")).find((s) => {
          if (s.closest('[data-attrid="kc:/local:lu attribute list"]')) return false;
          if (s.closest('[role="img"]')) return false;
          const tx = (s.textContent || "").trim();
          return (
            tx.length > 2 &&
            tx.length < 120 &&
            !/^\d+[.,]\d+$/.test(tx) &&
            !/^\(\d+\)$/.test(tx) &&
            (!nomeLower || tx.toLowerCase() !== nomeLower)
          );
        });
        if (catSpan) {
          categoria = (catSpan.textContent || "").trim();
          break;
        }
      }
    }

    return { nota, total_avaliacoes, categoria };
  }

  function normalizeOutboundWebsiteHref(href) {
    let website = href || "";
    try {
      if (website.startsWith("http") && website.includes("google.com/url"))
        website = new URL(website).searchParams.get("q") || website;
    } catch (_) {}
    return website;
  }

  function hrefLooksUnsuitableForBusinessWebsite(href) {
    if (!href) return true;
    const h = href.toLowerCase();
    if (h.startsWith("mailto:") || h.startsWith("tel:") || h.startsWith("javascript:"))
      return true;
    if (/maps\.google\.|google\.com\/maps/i.test(h)) return true;
    if (/google\.com\/search\?/i.test(h) && !/google\.com\/url/i.test(h)) return true;
    return false;
  }

  /** Site / Website = mesmo papel: link externo com rótulo acessível ou texto visível (sem classes dinâmicas). */
  function anchorLabelMeansSiteOrWebsite(a) {
    if (!a) return false;
    const al = (a.getAttribute("aria-label") || "").trim();
    if (/^website\s*:/i.test(al)) return true;
    if (/^(website|site)(\s|:|$)/i.test(al)) return true;
    const t = (a.textContent || "").replace(/\s+/g, " ").trim();
    if (/^(site|website)$/i.test(t)) return true;
    const spans = Array.from(a.querySelectorAll("span")).map((s) =>
      (s.textContent || "").replace(/\s+/g, " ").trim()
    );
    if (spans.some((p) => /^(site|website)$/i.test(p))) return true;
    return false;
  }

  function findOutboundWebsiteFromSiteAction(root) {
    if (!root) return null;
    const anchors = Array.from(
      root.querySelectorAll('a[href^="http://"], a[href^="https://"]')
    );
    for (const a of anchors) {
      const href = a.getAttribute("href") || a.href || "";
      if (hrefLooksUnsuitableForBusinessWebsite(href)) continue;
      if (!anchorLabelMeansSiteOrWebsite(a)) continue;
      return a;
    }
    return null;
  }

  function extractSobreHtml(root) {
    const regions = Array.from(root.querySelectorAll('div[role="region"]')).filter(
      (d) => {
        const al = d.getAttribute("aria-label") || "";
        return /^Sobre\b/i.test(al);
      }
    );
    if (!regions.length) return "";
    const last = regions[regions.length - 1];
    return last.innerHTML || "";
  }

  function extractFromImmersive(root) {
    const nomeEl =
      root.querySelector('h2[data-attrid="title"]') ||
      root.querySelector("h1") ||
      root.querySelector("h2");
    const nome = (nomeEl?.textContent || "").trim();

    const { nota, total_avaliacoes, categoria } = extractRatingReviewsCategory(root);

    const addrBtn = root.querySelector('[data-item-id="address"]');
    const addrAria = addrBtn?.getAttribute("aria-label") || "";
    let addrParsed = parseAddressFromAria(addrAria);
    if (!addrParsed.endereco_completo) {
      const txt = extractAddressTextFromKcLocation(root);
      if (txt) addrParsed = parseAddressFromText(txt);
    }

    let website = "";
    const webA = Array.from(root.querySelectorAll("a")).find((a) => {
      const al = a.getAttribute("aria-label") || "";
      return (
        /website\s*:/i.test(al) ||
        (a.getAttribute("data-tooltip") || "")
          .toLowerCase()
          .includes("abrir website")
      );
    });
    if (webA) website = normalizeOutboundWebsiteHref(webA.href || "");
    if (!website) {
      const siteLink = findOutboundWebsiteFromSiteAction(root);
      if (siteLink) website = normalizeOutboundWebsiteHref(siteLink.href || siteLink.getAttribute("href") || "");
    }

    const telefoneSeen = new Set();
    const telefones = [];
    function pushUniquePhone(raw) {
      const s = String(raw || "").trim();
      if (!s) return;
      const digits = s.replace(/\D/g, "");
      if (!digits || telefoneSeen.has(digits)) return;
      telefoneSeen.add(digits);
      telefones.push(s);
    }
    root.querySelectorAll('[data-item-id^="phone:"]').forEach((btn) => {
      const al = btn.getAttribute("aria-label") || "";
      const m = al.match(/Telefone:\s*(.+)$/i);
      const t = (m ? m[1] : al).trim();
      pushUniquePhone(t);
    });
    root.querySelectorAll("[data-phone-number]").forEach((el) => {
      pushUniquePhone(el.getAttribute("data-phone-number"));
    });

    if (!String(addrParsed.endereco_completo || "").trim()) {
      addrParsed = tryFillAddressFromMapsLink(root, addrParsed);
    }
    if (!telefones.length) {
      tryAppendPhonesFallback(root, telefoneSeen, pushUniquePhone);
    }

    let addweb = "nao";
    const addWebBtn = Array.from(root.querySelectorAll('div[role="button"], button')).find(
      (el) => (el.textContent || "").trim() === "Adicionar website"
    );
    if (addWebBtn) addweb = "sim";

    return {
      nome,
      nota,
      total_avaliacoes,
      categoria,
      endereco_completo: addrParsed.endereco_completo,
      cidade: addrParsed.cidade,
      uf: addrParsed.uf,
      cep: addrParsed.cep,
      website,
      telefones,
      addweb,
    };
  }

  async function closeImmersive() {
    const root = getImmersiveRoot();
    let closeBtn = null;
    if (root) {
      closeBtn =
        root.querySelector('[aria-label="Fechar"]') ||
        root.querySelector('button[aria-label="Close"]');
    }
    if (!closeBtn) {
      closeBtn =
        document.querySelector('[aria-label="Fechar"]') ||
        document.querySelector('button[aria-label="Close"]');
    }
    if (closeBtn) {
      await nativeClick(closeBtn);
      await sleep(100);
    }
    document.dispatchEvent(
      new KeyboardEvent("keydown", { key: "Escape", code: "Escape", bubbles: true })
    );
    document.dispatchEvent(
      new KeyboardEvent("keyup", { key: "Escape", code: "Escape", bubbles: true })
    );
    await sleep(100);
    if (!isImmersiveOpen()) return;
    await waitUntil(() => !isImmersiveOpen(), 3500, 100);
  }

  let webhpSearchDone = false;

  async function submitSearchFromWebhp(state) {
    if (webhpSearchDone) return;
    webhpSearchDone = true;
    const q =
      `${(state.keyword || "").trim()} ${(state.location || "").trim()}`.trim();
    const input =
      document.querySelector('textarea[name="q"]') ||
      document.querySelector('input[name="q"]');
    if (!input) {
      webhpSearchDone = false;
      await reportStatus("Campo de busca não encontrado.", "no_q");
      return;
    }
    input.focus();
    input.value = q;
    input.dispatchEvent(new Event("input", { bubbles: true }));
    await randomDelay(200, 400);
    const form = input.closest("form");
    if (form) {
      const btn =
        form.querySelector('button[type="submit"]') ||
        form.querySelector('input[type="submit"]');
      if (btn) {
        await nativeClick(btn);
      } else {
        form.requestSubmit?.();
      }
    } else {
      input.dispatchEvent(
        new KeyboardEvent("keydown", { key: "Enter", bubbles: true })
      );
    }
    await sleep(1000);
    await reportStatus(`Busca enviada: ${q}`);
  }

  async function preflightSubmit(state) {
    const q =
      `${(state.keyword || "").trim()} ${(state.location || "").trim()}`.trim();
    await stepGate(`Submeter busca em webhp?udm=1\nq = "${q}"`);
    await submitSearchFromWebhp(state);
  }

  async function waitWhilePaused() {
    while (true) {
      const s = await getState();
      if (!s.active) throw new Error("Coleta desativada.");
      if (!isPausedSync(s)) break;
      if (detectCaptcha()) throttleCaptchaAlert();
      await sleep(400);
    }
  }

  async function sendLeadWithRetries(payload, endpoint, token) {
    const delays = [1000, 3000, 8000];
    let lastErr = null;
    const body = { ...payload };
    delete body.token;

    for (let attempt = 0; attempt <= delays.length; attempt++) {
      await waitWhilePaused();
      if (detectCaptcha()) {
        throttleCaptchaAlert();
        await patchState({ paused: true });
        await reportStatus("Pausado: captcha durante envio ao servidor.");
        throw new Error("captcha");
      }
      try {
        const res = await fetch(endpoint, {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
            "X-Minerador-Token": token,
          },
          body: JSON.stringify(body),
        });
        const text = await res.text();
        let json = null;
        try {
          json = JSON.parse(text);
        } catch (_) {}
        if (!res.ok) {
          lastErr = `HTTP ${res.status}: ${text.slice(0, 200)}`;
        } else if (!json || json.ok !== true) {
          lastErr = json?.error || text.slice(0, 200) || "Resposta inválida";
        } else {
          return json;
        }
      } catch (e) {
        lastErr = String(e?.message || e);
      }
      if (attempt < delays.length) await sleep(delays[attempt]);
    }
    throw new Error(lastErr || "Falha ao enviar lead");
  }

  async function sendLeadsBatchWithRetries(batchBody, endpoint, token) {
    const delays = [1000, 3000, 8000];
    let lastErr = null;
    const body = { ...batchBody };
    delete body.token;

    for (let attempt = 0; attempt <= delays.length; attempt++) {
      await waitWhilePaused();
      if (detectCaptcha()) {
        throttleCaptchaAlert();
        await patchState({ paused: true });
        await reportStatus("Pausado: captcha durante envio ao servidor.");
        throw new Error("captcha");
      }
      try {
        const res = await fetch(endpoint, {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
            "X-Minerador-Token": token,
          },
          body: JSON.stringify(body),
        });
        const text = await res.text();
        let json = null;
        try {
          json = JSON.parse(text);
        } catch (_) {}
        if (!res.ok) {
          lastErr = `HTTP ${res.status}: ${text.slice(0, 200)}`;
        } else if (!json || json.ok !== true) {
          lastErr = json?.error || text.slice(0, 200) || "Resposta inválida";
        } else {
          return json;
        }
      } catch (e) {
        lastErr = String(e?.message || e);
      }
      if (attempt < delays.length) await sleep(delays[attempt]);
    }
    throw new Error(lastErr || "Falha ao enviar lote de leads");
  }

  function findNextPageHref() {
    const a = document.querySelector("a#pnnext");
    if (a && a.href) return a.href;
    const nav = Array.from(document.querySelectorAll('div[role="navigation"]')).find(
      (d) => (d.textContent || "").includes("Navegação de página")
    );
    if (nav) {
      const links = Array.from(nav.querySelectorAll("a[href]"));
      const withStart = links.filter((l) => /[?&]start=/.test(l.href));
      if (withStart.length) return withStart[withStart.length - 1].href;
    }
    return null;
  }

  async function scrapeCurrentPage(state) {
    lastStepModeOverlayHintMs = 0;
    const endpoint = (state.endpoint || "").trim();
    const token = (state.token || "").trim();
    const query = getQueryFromUrl();
    const pagina = currentPageNumber();
    const pageLeads = [];

    const hasAny = await waitUntil(() => getResultAnchors().length > 0, 28000);
    if (!hasAny) {
      await reportStatus(
        "Nenhum link de resultado encontrado em #search nesta página. Encerrado.",
        "no_results"
      );
      await patchState({
        active: false,
        paused: false,
        lastError: null,
      });
      return;
    }

    while (true) {
      const st = await getState();
      if (!st.active) return;
      if (isPausedSync(st)) {
        await sleep(500);
        continue;
      }
      if (detectCaptcha()) {
        throttleCaptchaAlert();
        await patchState({ paused: true });
        await reportStatus("Captcha detectado. Resolva e clique Continuar.");
        return;
      }

      const anchors = getResultAnchors();
      const next = anchors.find((el) => !processedHrefsThisPage.has(getResultTargetKey(el)));
      if (!next) break;

      const hrefKey = getResultTargetKey(next);
      processedHrefsThisPage.add(hrefKey);
      const serpPreview = extractFromSerpRow(next);

      const totalAnchors = anchors.length;
      const idxInPage = processedHrefsThisPage.size;
      await stepGate(
        "Clicar no resultado em #search: " + describeEl(next),
        `Lead ${idxInPage}/${totalAnchors} — página ${pagina}`
      );
      await nativeClick(next);
      await sleep(400);

      const opened = await waitUntil(() => isImmersiveOpen(), 22000);
      if (!opened) {
        if (DEBUG) {
          dlog(
            "Painel imersivo não abriu após o clique — sem extração; este resultado não entra no lote da página.",
            "Alvo:",
            describeEl(next),
            "url_resultado:",
            hrefKey
          );
        }
        await reportStatus(
          "Painel imersivo não abriu após o clique; este resultado será ignorado no lote da página. Avançando para o próximo.",
          null
        );
        continue;
      }

      await stepGate(
        "Painel de detalhes aberto (.immersive-container ou #local-place-viewer). Vou clicar em 'Visão geral'.",
        `Lead ${idxInPage}/${totalAnchors} — página ${pagina}`
      );

      const root = getImmersiveRoot();
      await clickVisaoGeral(root);
      await randomDelay(150, 350);

      let base = extractFromImmersive(root);
      base = applySerpPreviewToBase(serpPreview, base);
      await stepGate(
        "Extrai dados da Visão geral (nome/nota/avaliações/categoria preferem a lista SERP). Vou clicar em 'Sobre'.\nNome: " +
          (base.nome || "(vazio)") +
          "\nEndereço: " +
          (base.endereco_completo || "(vazio)") +
          "\nTelefones: " +
          JSON.stringify(base.telefones || []),
        `Lead ${idxInPage}/${totalAnchors} — página ${pagina}`
      );
      await clickSobreTab(root);
      await randomDelay(200, 450);
      const sobreHtml = extractSobreHtml(document.body);
      base = applyPanelFallbacks(base);

      const coletado_em = new Date().toISOString();
      const payload = {
        search_slug: state.searchSlug || null,
        keyword: state.keyword || "",
        localizacao: state.location || "",
        nome: base.nome,
        nota: base.nota,
        total_avaliacoes: base.total_avaliacoes,
        categoria: base.categoria,
        endereco_completo: base.endereco_completo,
        cidade: base.cidade,
        uf: base.uf,
        cep: base.cep,
        website: base.website,
        telefones: base.telefones,
        addweb: base.addweb,
        aba_sobre_html: sobreHtml,
        query,
        pagina,
        url_resultado: hrefKey,
        coletado_em,
      };

      pageLeads.push(payload);
      if (DEBUG) {
        dlog("Lead acumulado no lote da página:", hrefKey, "total no lote:", pageLeads.length);
      }

      await stepGate(
        `Lead ${idxInPage}/${totalAnchors} coletado (envio em lote ao final da página). Vou fechar o painel.`,
        `página ${pagina}`
      );

      await closeImmersive();
      await randomDelay(120, 350);
    }

    if (pageLeads.length > 0) {
      const batchBody = {
        search_slug: state.searchSlug || null,
        keyword: state.keyword || "",
        localizacao: state.location || "",
        query,
        pagina,
        leads: pageLeads,
      };
      await stepGate(
        `Enviar POST em lote (${pageLeads.length} lead(s)) para o servidor.\n` +
          previewBatchPayload(batchBody),
        endpoint
      );
      if (DEBUG) {
        dlog("Pré-POST lote, N =", pageLeads.length);
      }
      try {
        const res = await sendLeadsBatchWithRetries(batchBody, endpoint, token);
        const results = Array.isArray(res.results) ? res.results : [];
        let ins = 0;
        let dups = 0;
        let skipped = 0;
        for (const r of results) {
          if (!r || r.error) {
            skipped++;
            continue;
          }
          if (r.duplicate) dups++;
          else if (r.id != null) ins++;
        }
        const parts = [
          `${ins} novo(s)`,
          `${dups} duplicado(s)`,
          `${results.length} processado(s)`,
        ];
        if (skipped) parts.push(`${skipped} ignorado(s)`);
        await reportStatus(`Lote página ${pagina}: ${parts.join(", ")}.`);
        await stepGate(
          `Servidor OK (batch=true, count=${res.count ?? results.length}).`,
          endpoint
        );
      } catch (e) {
        const msg = String(e?.message || e);
        if (msg === "captcha") return;
        await reportStatus(`Erro ao enviar lote: ${msg}`, msg);
        await patchState({ paused: true, active: true });
        return;
      }
    } else if (DEBUG) {
      dlog("Nenhum lead coletado nesta página (painel não abriu em todos?); sem POST em lote.");
    }

    const nextHref = findNextPageHref();
    if (nextHref) {
      await stepGate("Ir para a próxima página: " + nextHref);
      await reportStatus("Indo para a próxima página…");
      processedHrefsThisPage.clear();
      location.href = nextHref;
      return;
    }

    await patchState({
      active: false,
      paused: false,
      statusText: "Concluído: todas as páginas processadas.",
      lastError: null,
    });
    await reportStatus("Concluído.");
  }

  async function loop() {
    if (running) return;
    running = true;
    try {
      while (true) {
        const state = await getState();
        if (!state.active) break;
        // Aceita qualquer subdomínio google.com (incluindo support/policies/accounts)
        // para que o guard-rail consiga voltar quando sair de www.google.com.
        if (!/\bgoogle\.com(?:\.br)?$/i.test(location.hostname)) {
          await sleep(600);
          continue;
        }
        if (!(await isThisTabAllowed())) {
          hideOverlay();
          await sleep(900);
          continue;
        }
        if (detectCaptcha()) {
          throttleCaptchaAlert();
          await reportStatus("Captcha / verificação ativa. Resolva no Google e use Continuar.");
          await sleep(1200);
          continue;
        }
        if (isPausedSync(state)) {
          await sleep(400);
          continue;
        }

        // Guard-rail: se a URL saiu do escopo (support/policies/accounts, etc.),
        // volta para a página anterior e segue.
        if (!isAllowedScrapeUrl()) {
          dlog("URL fora do escopo:", location.href);
          await reportStatus(
            "URL fora do escopo de coleta; voltando: " + location.href
          );
          try {
            history.back();
          } catch (_) {
            location.href = WEBHP;
          }
          await sleep(1500);
          continue;
        }

        if (isWebhpUdm() && !getQueryFromUrl()) {
          await preflightSubmit(state);
          await sleep(2500);
          continue;
        }

        if (hasSearchResultsLayout() && getQueryFromUrl()) {
          await scrapeCurrentPage(state);
          await sleep(500);
          continue;
        }

        if (!isWebhpUdm() && !hasSearchResultsLayout()) {
          await reportStatus("Abrindo página inicial Google Local…");
          location.href = WEBHP;
          await sleep(2000);
          continue;
        }

        await sleep(800);
      }
    } catch (e) {
      console.error(e);
      await reportStatus("Erro interno do minerador.", String(e));
    } finally {
      running = false;
    }
  }

  function kick() {
    loop().catch(console.error);
  }

  chrome.storage.onChanged.addListener((changes, area) => {
    if (area !== "local") return;
    if (changes[STORAGE_KEY]) {
      myTabAllowed = null;
      kick();
    }
  });

  kick();
})();
