<?php
declare(strict_types=1);

/**
 * Desafios: emoji + respostas aceites (após normalização: minúsculas, sem acento).
 *
 * @return list<array{emoji: string, answers: list<string>}>
 */
function minerador_login_captcha_challenges(): array
{
    return [
        ['emoji' => '🐶', 'answers' => ['cachorro', 'cao', 'cão', 'cadela', 'animal', 'mamifero', 'mamífero']],
        ['emoji' => '🐱', 'answers' => ['gato', 'gata', 'felino', 'animal', 'mamifero', 'mamífero']],
        ['emoji' => '🐦', 'answers' => ['passaro', 'pássaro', 'ave', 'animal']],
        ['emoji' => '🐟', 'answers' => ['peixe', 'animal', 'peixinho']],
        ['emoji' => '🍎', 'answers' => ['maca', 'maçã', 'fruta']],
        ['emoji' => '🍌', 'answers' => ['banana', 'fruta']],
        ['emoji' => '🍕', 'answers' => ['pizza', 'comida']],
        ['emoji' => '🚗', 'answers' => ['carro', 'veiculo', 'veículo', 'automovel', 'automóvel']],
        ['emoji' => '✈️', 'answers' => ['aviao', 'avião', 'aeronave']],
        ['emoji' => '🏠', 'answers' => ['casa', 'lar', 'moradia', 'edificio', 'edifício']],
        ['emoji' => '⚽', 'answers' => ['bola', 'futebol', 'esporte']],
        ['emoji' => '🎸', 'answers' => ['guitarra', 'violao', 'violão', 'instrumento', 'musica', 'música']],
        ['emoji' => '📱', 'answers' => ['celular', 'telefone', 'smartphone', 'aparelho', 'movel', 'móvel']],
        ['emoji' => '💡', 'answers' => ['lampada', 'lâmpada', 'ideia', 'luz']],
        ['emoji' => '🔑', 'answers' => ['chave']],
        ['emoji' => '⏰', 'answers' => ['relogio', 'relógio', 'despertador', 'tempo', 'hora']],
        ['emoji' => '🌙', 'answers' => ['lua', 'noite', 'satelite', 'satélite']],
        ['emoji' => '☀️', 'answers' => ['sol', 'dia', 'estrela']],
        ['emoji' => '🌧️', 'answers' => ['chuva', 'temporal', 'nuvem']],
        ['emoji' => '🌳', 'answers' => ['arvore', 'árvore', 'natureza', 'planta']],
        ['emoji' => '❤️', 'answers' => ['coração', 'coracao', 'amor']],
        ['emoji' => '👤', 'answers' => ['pessoa', 'homem', 'silhueta', 'usuario', 'utilizador']],
        ['emoji' => '👁️', 'answers' => ['olho', 'vista', 'ver']],
        ['emoji' => '🦷', 'answers' => ['dente', 'dentadura']],
        ['emoji' => '🧠', 'answers' => ['cerebro', 'cérebro', 'mente']],
        ['emoji' => '🔥', 'answers' => ['fogo', 'chama', 'incendio', 'incêndio']],
        ['emoji' => '⭐', 'answers' => ['estrela']],
        ['emoji' => '🎂', 'answers' => ['bolo', 'aniversario', 'aniversário', 'festa']],
    ];
}
