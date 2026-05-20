<?php
if (isset($_GET['action']) && $_GET['action'] === 'analyze') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) {
            throw new Exception('JSON inválido.');
        }
        echo json_encode(['ok' => true, 'result' => analyze_repo($input)], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
    exit;
}

function analyze_repo(array $input): array
{
    $repoUrl = trim($input['repo_url'] ?? '');
    $prompt = trim($input['prompt'] ?? '');
    $githubToken = trim($input['github_token'] ?? '');

    if ($repoUrl === '') throw new Exception('Informe a URL do repositório GitHub.');
    if ($prompt === '') throw new Exception('Escreva o prompt da tarefa.');

    [$owner, $repo] = parse_github_repo($repoUrl);
    $headers = ['User-Agent: Token-Miser/1.0', 'Accept: application/vnd.github+json'];
    if ($githubToken !== '') {
        $headers[] = 'Authorization: Bearer ' . $githubToken;
    }

    $repoMeta = github_get_json("https://api.github.com/repos/$owner/$repo", $headers);
    $branch = $repoMeta['default_branch'] ?? 'main';
    $tree = github_get_json("https://api.github.com/repos/$owner/$repo/git/trees/" . rawurlencode($branch) . "?recursive=1", $headers);
    if (empty($tree['tree'])) throw new Exception('Não consegui ler a árvore do repositório.');

    $taskType = detect_task_type($prompt, $repo, $tree['tree']);
    $allFiles = [];
    foreach ($tree['tree'] as $item) {
        if (($item['type'] ?? '') !== 'blob') continue;
        $path = (string)($item['path'] ?? '');
        $size = intval($item['size'] ?? 0);
        if (!is_candidate_text_file($path, $size)) continue;
        $score = score_file($path, $prompt, $taskType);
        if ($score <= 0) continue;
        $allFiles[] = ['path' => $path, 'sha' => $item['sha'] ?? '', 'size' => $size, 'score' => $score];
    }

    usort($allFiles, fn($a, $b) => $b['score'] === $a['score'] ? $a['size'] <=> $b['size'] : $b['score'] <=> $a['score']);
    $selected = array_slice($allFiles, 0, min(8, max(4, count($allFiles))));

    $fileRows = [];
    $fileTokens = 0;
    foreach ($selected as $file) {
        $tokens = $file['size'] > 450000
            ? approximate_tokens_from_length($file['size'])
            : estimate_tokens(fetch_blob_text($owner, $repo, $file['sha'], $headers));
        $fileTokens += $tokens;
        $fileRows[] = [
            'path' => $file['path'],
            'tokens' => $tokens,
            'size' => $file['size'],
            'score' => $file['score'],
        ];
    }

    $promptTokens = estimate_tokens($prompt);
    $inputTokens = $promptTokens + $fileTokens;
    $outputTokens = estimate_output_tokens($taskType['size'], $prompt);
    $comparisons = build_comparisons($inputTokens, $outputTokens);
    usort($comparisons, fn($a, $b) => $a['credits'] <=> $b['credits']);

    $recommendation = build_recommendation($comparisons, $taskType, $inputTokens, $prompt);

    return [
        'repo' => "$owner/$repo",
        'branch' => $branch,
        'task_type' => $taskType,
        'prompt_tokens' => $promptTokens,
        'input_tokens_direct' => $inputTokens,
        'output_tokens_estimated' => $outputTokens,
        'selected_files' => $fileRows,
        'comparisons' => $comparisons,
        'recommendation' => $recommendation,
        'hourly_context_note' => build_hourly_note($recommendation, $promptTokens, $inputTokens),
        'optimized_prompt' => build_optimized_prompt($prompt, $selected, $recommendation['recommended_model'] ?? 'GPT-5.4-mini'),
        'accuracy_note' => 'Estimativa: não inclui tool calls, loops internos, cache real, nem arquivos adicionais abertos depois.',
    ];
}

function parse_github_repo(string $url): array
{
    if (preg_match('~github\.com[:/]+([^/\s]+)/([^/\s#?]+)~i', trim($url), $m)) {
        return [$m[1], preg_replace('~\.git$~', '', $m[2])];
    }
    if (preg_match('~^([^/\s]+)/([^/\s]+)$~', trim($url), $m)) {
        return [$m[1], preg_replace('~\.git$~', '', $m[2])];
    }
    throw new Exception('URL inválida. Use algo como https://github.com/usuario/repositorio.');
}

function github_get_json(string $url, array $headers): array
{
    $body = http_get($url, $headers);
    $data = json_decode($body, true);
    if (!is_array($data)) throw new Exception('Resposta inválida da API do GitHub.');
    if (!empty($data['message']) && isset($data['documentation_url'])) throw new Exception('GitHub API: ' . $data['message']);
    return $data;
}

function http_get(string $url, array $headers): string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => $headers]);
        $body = curl_exec($ch);
        $err = curl_error($ch);
        $code = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
        curl_close($ch);
        if ($body === false || $code >= 400) throw new Exception("Erro ao acessar GitHub ($code): " . ($err ?: substr((string)$body, 0, 250)));
        return $body;
    }
    $context = stream_context_create(['http' => ['method' => 'GET', 'header' => implode("\r\n", $headers), 'timeout' => 30]]);
    $body = @file_get_contents($url, false, $context);
    if ($body === false) throw new Exception('Erro ao acessar GitHub.');
    return $body;
}

function fetch_blob_text(string $owner, string $repo, string $sha, array $headers): string
{
    $blob = github_get_json("https://api.github.com/repos/$owner/$repo/git/blobs/$sha", $headers);
    return (($blob['encoding'] ?? '') === 'base64') ? (base64_decode((string)($blob['content'] ?? '')) ?: '') : (string)($blob['content'] ?? '');
}

function detect_task_type(string $prompt, string $repo, array $tree): array
{
    $p = mb_strtolower($prompt, 'UTF-8');
    $r = mb_strtolower($repo, 'UTF-8');
    $paths = [];
    foreach ($tree as $item) {
        if (($item['type'] ?? '') === 'blob') $paths[] = mb_strtolower((string)($item['path'] ?? ''), 'UTF-8');
    }
    $hay = implode("\n", array_slice($paths, 0, 250));

    $hardRefactorSignals = [
        'refatore tudo',
        'reorganize a estrutura',
        'reorganizar a estrutura',
        'projeto inteiro',
        'projeto todo',
        'pastas e arquivos',
        'arquivos e pastas',
        'limpar o projeto',
        'refatoração grande',
        'refatoracao grande',
    ];
    $hardBugSignals = [
        'procure erros e conserte',
        'procure erros',
        'conserte-os',
        'conserte os erros',
        'corrija erros',
        'corrigir erros',
    ];
    $hardRefactorHit = false;
    foreach ($hardRefactorSignals as $signal) {
        if (str_contains($p, $signal)) {
            $hardRefactorHit = true;
            break;
        }
    }
    $hardBugHit = false;
    foreach ($hardBugSignals as $signal) {
        if (str_contains($p, $signal)) {
            $hardBugHit = true;
            break;
        }
    }
    if ($hardRefactorHit) {
        return [
            'type' => $hardBugHit ? 'refactor' : 'refactor',
            'size' => 'large',
            'confidence' => 0.92,
        ];
    }
    if ($hardBugHit && str_contains($p, 'projeto inteiro')) {
        return [
            'type' => 'refactor',
            'size' => 'large',
            'confidence' => 0.86,
        ];
    }

    $rules = [
        ['type' => 'webhook/deploy', 'size' => 'tiny', 'terms' => ['webhook', 'deploy', 'push', 'assinatura', 'github action', 'github actions', 'webhook do github'], 'paths' => ['webhook', 'deploy', '.github']],
        ['type' => 'frontend/ui', 'size' => 'small', 'terms' => ['tela', 'layout', 'ui', 'interface', 'css', 'html', 'modal', 'botão', 'botao', 'card', 'menu', 'responsivo', 'mobile'], 'paths' => ['css', 'html', 'js', 'assets', 'views', 'templates']],
        ['type' => 'backend/php', 'size' => 'small', 'terms' => ['php', 'endpoint', 'api', 'handler', 'post', 'get', 'include', 'require', 'sessao', 'sessão'], 'paths' => ['.php', 'api', 'handler', 'config']],
        ['type' => 'database', 'size' => 'medium', 'terms' => ['banco', 'sql', 'mysql', 'query', 'pdo', 'mysqli', 'schema', 'migration', 'relacao', 'relação'], 'paths' => ['sql', 'db', 'database', 'migration']],
        ['type' => 'automation', 'size' => 'medium', 'terms' => ['whatsapp', 'bot', 'cron', 'worker', 'automação', 'automacao', 'fila', 'job', 'agendamento'], 'paths' => ['bot', 'whatsapp', 'cron', 'worker', 'queue', 'job']],
        ['type' => 'bugfix', 'size' => 'small', 'terms' => ['bug', 'erro', 'corrigir', 'consertar', 'quebrado', 'falha', 'não funciona', 'nao funciona', 'não abre', 'nao abre', 'não salva', 'nao salva', 'parou'], 'paths' => []],
        ['type' => 'refactor', 'size' => 'large', 'terms' => ['refator', 'reorganizar', 'estruturar', 'limpar', 'simplificar', 'reduzir duplicação', 'reduzir duplicacao'], 'paths' => []],
        ['type' => 'docs/content', 'size' => 'tiny', 'terms' => ['readme', 'document', 'docs', 'texto', 'conteúdo', 'conteudo', 'copy', 'redação', 'redacao'], 'paths' => ['readme', '.md']],
        ['type' => 'diagnostic', 'size' => 'medium', 'terms' => ['investigue', 'descubra', 'diagnostique', 'analise', 'análise', 'por que', 'porque', 'onde está', 'onde esta'], 'paths' => []],
    ];
    foreach ($rules as $rule) {
        $promptScore = 0;
        $repoScore = 0;
        foreach ($rule['terms'] as $term) {
            if (str_contains($p, $term)) $promptScore += 3;
            if (str_contains($hay, $term)) $repoScore += 1;
        }
        foreach ($rule['paths'] as $term) {
            if (str_contains($r, $term)) $repoScore += 2;
            if (str_contains($hay, $term)) $repoScore += 1;
        }
        if ($promptScore >= 3 && ($promptScore + $repoScore) >= 5) {
            return ['type' => $rule['type'], 'size' => $rule['size'], 'confidence' => min(0.97, 0.38 + (($promptScore + $repoScore) * 0.06))];
        }
    }

    $complexity = 0;
    foreach ([
        'corrigir' => 2, 'ajustar' => 1, 'implementar' => 2, 'migrar' => 3, 'refator' => 3,
        'simplificar' => 2, 'reorganizar' => 3, 'otimizar' => 2, 'investigar' => 2, 'descobrir' => 2,
        'vários arquivos' => 3, 'varios arquivos' => 3, 'multiplos arquivos' => 3, 'múltiplos arquivos' => 3,
        'sem refatorar' => -1, 'menor diff' => -1, 'apenas' => -1, 'só' => -1, 'so' => -1,
    ] as $term => $weight) {
        if (str_contains($p, $term)) $complexity += $weight;
    }

    if (preg_match_all('/[\p{L}\p{N}_-]{4,}/u', $p, $matches)) {
        $uniqueWords = count(array_unique($matches[0] ?? []));
        if ($uniqueWords > 22) $complexity += 2;
        if ($uniqueWords > 35) $complexity += 2;
    }

    $hasMoreThanOneArea = 0;
    foreach (['php', 'js', 'css', 'html', 'sql', 'api', 'webhook', 'deploy', 'whatsapp', 'bot', 'cron'] as $needle) {
        if (str_contains($p, $needle)) $hasMoreThanOneArea += 2;
        if (str_contains($hay, $needle)) $hasMoreThanOneArea++;
    }
    if ($hasMoreThanOneArea >= 4) $complexity += 2;

    if ($complexity >= 8) return ['type' => 'refactor', 'size' => 'large', 'confidence' => 0.68];
    if ($complexity >= 5) return ['type' => 'backend/php', 'size' => 'medium', 'confidence' => 0.58];
    if ($complexity >= 2) return ['type' => 'bugfix', 'size' => 'small', 'confidence' => 0.52];

    if ($complexity <= -1) return ['type' => 'general', 'size' => 'tiny', 'confidence' => 0.35];
    return ['type' => 'general', 'size' => 'medium', 'confidence' => 0.45];
}

function is_candidate_text_file(string $path, int $size): bool
{
    $lower = strtolower($path);
    foreach (['.git/', 'node_modules/', 'vendor/', 'dist/', 'build/', 'storage/', 'logs/', 'cache/', 'tmp/'] as $dir) {
        if (str_contains($lower, $dir)) return false;
    }
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if ($size <= 0 || $size > 1500000) return false;
    if (in_array($ext, ['png','jpg','jpeg','gif','webp','ico','pdf','zip','rar','7z','gz','tar','mp4','mov','avi','mp3','wav','ttf','otf','woff','woff2','exe','dll','bin','sqlite','db'], true)) return false;
    return in_array($ext, ['php','js','ts','tsx','jsx','css','scss','html','htm','json','md','txt','sql','py','yml','yaml','xml','env','ini','conf','sh','bat','ps1','vue','svelte','cjs','mjs','lock'], true) || basename($path) === '.htaccess';
}

function score_file(string $path, string $prompt, array $taskType): int
{
    $p = mb_strtolower($prompt, 'UTF-8');
    $pathLower = mb_strtolower($path, 'UTF-8');
    $score = 0;
    foreach (extract_keywords($p) as $kw) {
        if (mb_strlen($kw, 'UTF-8') >= 3 && (str_contains($pathLower, $kw) || str_contains(basename($pathLower), $kw))) $score += 10;
    }
    $maps = [
        'webhook/deploy' => ['webhook', 'deploy', 'github', 'handler'],
        'frontend/ui' => ['index.php', '.css', '.js', 'modal', 'button', 'style'],
        'backend/php' => ['.php', 'api', 'handler', 'controller'],
        'database' => ['sql', 'db', 'database', 'mysql', 'pdo', 'mysqli'],
        'automation' => ['whatsapp', 'bot', 'cron', 'worker'],
        'bugfix' => ['index.php', '.php', '.js', '.css', 'handler'],
        'refactor' => ['index.php', '.php', '.js', '.css'],
        'docs/content' => ['readme', '.md', 'docs'],
        'general' => ['index.php', '.php', '.js', '.css'],
    ];
    $task = (string)($taskType['type'] ?? 'general');
    foreach (($maps[$task] ?? []) as $needle) if (str_contains($pathLower, $needle)) $score += 20;
    return max(0, $score);
}

function extract_keywords(string $prompt): array
{
    preg_match_all('/[\p{L}\p{N}_-]{3,}/u', $prompt, $matches);
    $stop = array_flip(['para','com','que','uma','por','dos','das','nos','nas','esse','essa','isso','aquele','aquela','corrija','crie','faça','faca','alterar','altere','ajuste','usar','use','somente','arquivo','arquivos','projeto','repositorio','repositório','preciso','quero','codex','token','tokens','github','branch','main','master','the','and','for','with']);
    $out = [];
    foreach ($matches[0] ?? [] as $w) {
        $w = mb_strtolower($w, 'UTF-8');
        if (!isset($stop[$w])) $out[$w] = true;
    }
    return array_keys($out);
}

function estimate_tokens(string $text): int
{
    $len = strlen($text);
    if ($len === 0) return 0;
    preg_match_all('/[^\s]/u', $text, $nonSpace);
    return (int)ceil(max($len / 3.6, count($nonSpace[0] ?? []) / 2.9));
}

function approximate_tokens_from_length(int $bytes): int { return (int)ceil($bytes / 3.6); }

function estimate_output_tokens(string $taskSize, string $prompt): int
{
    $map = ['tiny' => 1200, 'small' => 2200, 'medium' => 4200, 'large' => 7600];
    $base = $map[$taskSize] ?? 4200;
    $p = mb_strtolower($prompt, 'UTF-8');
    if (str_contains($p, 'detalh') || str_contains($p, 'document')) $base = (int)ceil($base * 1.25);
    if (str_contains($p, 'resumo curto') || str_contains($p, 'sem explic')) $base = (int)ceil($base * 0.7);
    return $base;
}

function build_comparisons(int $inputTokens, int $outputTokens): array
{
    $rates = codex_rates();
    $speeds = ['normal' => 1.0, 'fast' => 1.5, 'turbo' => 2.0];
    $scenarios = ['direto' => 1.0, 'realista' => 1.2, 'pessimista' => 1.5];
    $rows = [];
    foreach ($rates as $model => $rate) {
        foreach ($speeds as $speed => $multiplier) {
            foreach ($scenarios as $scenario => $scenarioMultiplier) {
                $scenarioInput = (int)ceil($inputTokens * $scenarioMultiplier);
                $scenarioOutput = (int)ceil($outputTokens * ($scenario === 'pessimista' ? 1.2 : ($scenario === 'realista' ? 1.08 : 1.0)));
                $rows[] = [
                    'model' => $model,
                    'speed' => $speed,
                    'scenario' => $scenario,
                    'input_tokens' => $scenarioInput,
                    'output_tokens' => $scenarioOutput,
                    'total_tokens' => $scenarioInput + $scenarioOutput,
                    'credits' => round(estimate_credits($scenarioInput, 0, $scenarioOutput, $rate, $multiplier), 4),
                ];
            }
        }
    }
    return $rows;
}

function codex_rates(): array
{
    return [
        'GPT-5.4-mini' => ['input' => 18.75, 'cached' => 1.875, 'output' => 113],
        'GPT-5.3-Codex' => ['input' => 43.75, 'cached' => 4.375, 'output' => 350],
        'GPT-5.4' => ['input' => 62.50, 'cached' => 6.25, 'output' => 375],
        'GPT-5.5' => ['input' => 125.0, 'cached' => 12.5, 'output' => 750],
    ];
}

function estimate_credits(int $inputTokens, int $cachedTokens, int $outputTokens, array $rate, float $speedMultiplier): float
{
    return ((($inputTokens / 1000000) * $rate['input']) + (($cachedTokens / 1000000) * $rate['cached']) + (($outputTokens / 1000000) * $rate['output'])) * max(0.1, $speedMultiplier);
}

function build_recommendation(array $comparisons, array $taskType, int $inputTokens, string $prompt): array
{
    $normal = array_values(array_filter($comparisons, fn($c) => $c['scenario'] === 'realista' && $c['speed'] === 'normal'));
    usort($normal, fn($a, $b) => $a['credits'] <=> $b['credits']);
    $best = $normal[0] ?? null;
    $type = (string)($taskType['type'] ?? 'general');
    $size = (string)($taskType['size'] ?? 'medium');
    $confidence = (float)($taskType['confidence'] ?? 0.45);
    $risk = $size === 'large' || $inputTokens > 120000 ? 'médio' : 'baixo';

    $recommended = 'GPT-5.4-mini';

    if ($size === 'tiny' && $confidence >= 0.55) {
        $recommended = 'GPT-5.4-mini';
    } elseif ($size === 'small' && $confidence >= 0.55) {
        $recommended = 'GPT-5.4-mini';
    } elseif ($type === 'backend/php' || $type === 'bugfix') {
        $recommended = $confidence >= 0.62 ? 'GPT-5.3-Codex' : 'GPT-5.4-mini';
    } elseif ($type === 'database' || $type === 'automation' || $type === 'refactor') {
        $recommended = $type === 'refactor' && ($confidence >= 0.8 || $inputTokens > 70000) ? 'GPT-5.5' : 'GPT-5.3-Codex';
        if ($type === 'refactor' && $recommended === 'GPT-5.5') {
            $risk = 'alto';
        }
    } elseif ($type === 'diagnostic') {
        $recommended = $inputTokens > 50000 ? 'GPT-5.5' : 'GPT-5.3-Codex';
        $risk = $inputTokens > 50000 ? 'médio' : 'baixo';
    } elseif ($type === 'frontend/ui') {
        $recommended = $confidence >= 0.7 || $inputTokens > 45000 ? 'GPT-5.3-Codex' : 'GPT-5.4-mini';
    } elseif ($type === 'webhook/deploy') {
        $recommended = $confidence >= 0.65 ? 'GPT-5.4-mini' : 'GPT-5.3-Codex';
    } elseif ($inputTokens > 180000) {
        $recommended = 'GPT-5.3-Codex';
        $risk = 'alto';
    } elseif ($inputTokens > 90000) {
        $recommended = 'GPT-5.3-Codex';
    } elseif ($inputTokens > 45000 && $confidence < 0.58) {
        $recommended = 'GPT-5.3-Codex';
    }

    if ($type === 'refactor' && $inputTokens > 120000) {
        $recommended = 'GPT-5.5';
        $risk = 'alto';
    }

    if ($confidence < 0.5 && $inputTokens > 30000) {
        $risk = $risk === 'alto' ? 'alto' : 'médio';
        if ($recommended === 'GPT-5.4-mini') {
            $recommended = 'GPT-5.3-Codex';
        }
    }

    return [
        'recommended_model' => $recommended,
        'risk' => $risk,
        'reason' => 'Tipo detectado: ' . $type . ' · confiança ' . number_format($confidence * 100, 0) . '%. O comparativo abaixo mostra o menor custo estimado, mas a recomendação já sobe quando a tarefa parece maior ou mais ambígua.',
        'best_cheapest_realistic_normal' => $best,
        'selected_file_count' => count($comparisons),
    ];
}

function build_hourly_note(array $recommendation, int $promptTokens, int $inputTokens): string
{
    $best = $recommendation['best_cheapest_realistic_normal'] ?? null;
    $bestCredits = is_array($best) ? (float)($best['credits'] ?? 0) : 0.0;
    $impact = $inputTokens > 0 ? round(($promptTokens / max(1, $inputTokens)) * 100, 1) : 0.0;
    return "Este prompt representa cerca de {$impact}% do contexto estimado. No cenário realista/normal, a opção mais barata ficou em {$bestCredits} créditos. Isso dá uma noção prática do impacto por hora, sem depender de login/billing.";
}

function example_prompts(): array
{
    return [
        ['label' => 'Webhook simples', 'prompt' => 'Ajuste o webhook do GitHub para atualizar o servidor automaticamente sem quebrar o deploy. Quero a menor mudança possível.'],
        ['label' => 'Bug de interface', 'prompt' => 'No tokens, reduza a complexidade da tela, deixe só prompt e URL do repositório, e corrija qualquer problema de layout no mobile.'],
        ['label' => 'Investigação', 'prompt' => 'Investigue por que o fluxo de deploy pode falhar em alguns commits e me diga onde está a causa com o menor diff possível.'],
        ['label' => 'Banco de dados', 'prompt' => 'Analise as queries e as tabelas relacionadas e sugira a alteração mínima para evitar duplicidade e melhorar a leitura dos dados.'],
        ['label' => 'Refatoração grande', 'prompt' => 'Reorganize a base, simplifique os arquivos principais e remova duplicação, mas sem mudar o comportamento final.'],
    ];
}

function build_optimized_prompt(string $prompt, array $selected, string $model): string
{
    $paths = array_slice(array_map(fn($f) => $f['path'], $selected), 0, 10);
    return trim("Use {$model}.\n\nTarefa:\n{$prompt}\n\nLeia primeiro:\n- " . implode("\n- ", $paths) . "\n\nRegras:\n- Use o AGENTS.md se existir.\n- Faça o menor diff possível.\n- Não refatore sem pedido explícito.\n- No final, responda com arquivos alterados, resumo curto e como testar.");
}
?>
<!doctype html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <title>Token Miser - Estimador pré-play do Codex</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        :root{--bg:#09090d;--panel:#11111a;--muted:#9ba0ad;--text:#f5f7fb;--line:rgba(255,255,255,.10);--red:#ff3b5f;--green:#36d399;--yellow:#fbbf24;--blue:#60a5fa;--shadow:0 24px 70px rgba(0,0,0,.45);--radius:18px}
        *{box-sizing:border-box} body{margin:0;min-height:100vh;background:linear-gradient(135deg,#07070a,#11111a 45%,#09090d);color:var(--text);font-family:Inter,ui-sans-serif,system-ui,sans-serif}
        .wrap{max-width:1180px;margin:0 auto;padding:28px 18px 64px}.hero{display:grid;grid-template-columns:1.2fr .8fr;gap:18px;margin-bottom:18px}.card{background:linear-gradient(180deg,rgba(255,255,255,.06),rgba(255,255,255,.025));border:1px solid var(--line);border-radius:var(--radius);box-shadow:var(--shadow)}.hero-main{padding:30px}.eyebrow{display:inline-flex;padding:7px 12px;border-radius:999px;border:1px solid rgba(255,59,95,.35);background:rgba(255,59,95,.12);color:#ffd3dc;font-size:13px;font-weight:700}.hero h1{font-size:clamp(32px,4vw,54px);line-height:1.02;margin:16px 0 12px}.lead{color:#c8ccd8;font-size:18px;line-height:1.5;margin:0}.hero-side{padding:18px;display:flex;flex-direction:column;gap:12px}.mini-stat{padding:16px;border:1px solid var(--line);border-radius:16px;background:rgba(0,0,0,.22)}.mini-stat b{display:block;font-size:24px;margin-bottom:4px}.mini-stat span{color:var(--muted);font-size:13px}.form{padding:20px;display:grid;gap:14px}.row{display:grid;grid-template-columns:1fr auto;gap:12px;align-items:end}label{display:block;font-weight:800;font-size:13px;margin:0 0 8px}input,textarea{width:100%;border:1px solid var(--line);background:#0b0b12;color:var(--text);border-radius:14px;padding:13px 14px;font:inherit}.textarea{min-height:160px;resize:vertical}.actions{display:flex;gap:10px;flex-wrap:wrap}.btn,.help-toggle{border:0;border-radius:16px;padding:13px 16px;font-weight:900;cursor:pointer}.btn{background:linear-gradient(135deg,var(--red),#7c3aed);color:#fff;box-shadow:0 14px 35px rgba(255,59,95,.22)}.help-toggle{background:rgba(255,255,255,.06);color:var(--text);border:1px solid var(--line)}.loading{display:none;padding-top:6px}.loading.open{display:block}.loading-head{display:flex;justify-content:space-between;gap:12px;align-items:center;margin-bottom:8px;font-size:13px;font-weight:800;color:#dfe5f2}.loading-bar{height:10px;border-radius:999px;background:rgba(255,255,255,.08);overflow:hidden;border:1px solid var(--line)}.loading-bar i{display:block;height:100%;width:0%;border-radius:999px;background:linear-gradient(90deg,var(--green),var(--yellow),var(--red));transition:width .22s ease}.loading.pulse .loading-bar i{animation:pulse 1.1s ease-in-out infinite}@keyframes pulse{0%,100%{opacity:.85}50%{opacity:1}}.results{display:flex;flex-direction:column;gap:16px;margin-top:18px}.panel{padding:20px}.summary{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}.metric{padding:16px;border:1px solid var(--line);border-radius:16px;background:rgba(0,0,0,.24)}.metric small{display:block;color:var(--muted);font-size:12px}.metric b{display:block;font-size:22px;margin-top:6px}.badge{display:inline-flex;padding:7px 10px;border-radius:999px;border:1px solid var(--line);font-size:12px;font-weight:900}.badge.green{color:#b9f8dc;background:rgba(54,211,153,.12)}.badge.yellow{color:#fff1b8;background:rgba(251,191,36,.12)}.badge.red{color:#ffd0d8;background:rgba(255,59,95,.12)}.recommend{display:flex;justify-content:space-between;gap:12px;align-items:start;padding:18px;border-radius:18px;border:1px solid var(--line);background:rgba(0,0,0,.24)}.scroll{overflow:auto;border:1px solid var(--line);border-radius:16px}.bar{height:9px;border-radius:999px;background:rgba(255,255,255,.08);overflow:hidden;min-width:110px}.bar i{display:block;height:100%;border-radius:999px;background:linear-gradient(90deg,var(--green),var(--yellow),var(--red))}table{width:100%;border-collapse:collapse}th,td{padding:12px 10px;border-bottom:1px solid var(--line);text-align:left;font-size:14px}th{color:#dce0ea;font-size:12px;text-transform:uppercase;letter-spacing:.06em;background:rgba(255,255,255,.04)}.muted{color:var(--muted)}.helpbox{display:none;padding:18px}.helpbox.open{display:block}.helpgrid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}.helpitem{padding:16px;border:1px solid var(--line);border-radius:16px;background:rgba(0,0,0,.22)}.helpitem b{display:block;margin-bottom:6px}.helpitem span{color:var(--muted);font-size:13px;line-height:1.45}.empty{padding:30px;text-align:center;color:var(--muted)}.footer-note{color:var(--muted);font-size:12px;line-height:1.45;margin-top:12px}@media(max-width:900px){.hero,.summary,.helpgrid{grid-template-columns:1fr}.row{grid-template-columns:1fr}.lead{font-size:16px}.loading-head{flex-direction:column;align-items:flex-start}}
    </style>
</head>
<body>
<div class="wrap">
    <section class="hero">
        <div class="card hero-main">
            <div class="eyebrow">Token Miser</div>
            <h1>Descubra o modelo mais barato antes de dar play.</h1>
            <p class="lead">Cole o prompt e a URL do repositório. O app identifica o tipo de tarefa sozinho, estima o impacto e mostra o modelo e a velocidade mais econômicos com uma tabela comparativa logo abaixo.</p>
        </div>
        <div class="card hero-side">
            <div class="mini-stat"><b>Automático</b><span>tipo de tarefa detectado pelo prompt e pelo repositório</span></div>
            <div class="mini-stat"><b>Comparativo curto</b><span>modelo mais barato primeiro, tabela logo em seguida</span></div>
            <div class="mini-stat"><b>Ajuda simples</b><span>botão com noção de impacto por hora, sem login</span></div>
        </div>
    </section>

    <section class="card form">
        <div>
            <label for="repo">Repositório GitHub</label>
            <input id="repo" placeholder="https://github.com/danielaraujodasilva/tokens">
        </div>
        <div>
            <label for="prompt">Prompt</label>
            <textarea id="prompt" class="textarea" placeholder="Ex: corrige o webhook do deploy e deixa tudo mais simples."></textarea>
        </div>
        <div class="actions">
            <button class="btn" id="analyzeBtn">Analisar custo mínimo</button>
            <button class="help-toggle" id="helpBtn" type="button">Como ler os créditos</button>
        </div>
        <div class="loading" id="loadingState" aria-live="polite">
            <div class="loading-head">
                <span id="loadingPercent">0%</span>
                <span id="loadingText">Pronto para analisar.</span>
            </div>
            <div class="loading-bar"><i id="loadingBar"></i></div>
        </div>
    </section>

    <section class="card helpbox" id="helpBox">
        <h2 style="margin:0 0 8px;">Informativo rápido</h2>
        <p class="footer-note" style="margin-top:0;">Não vale a pena tentar puxar saldo real da conta aqui porque isso exigiria autenticação e integração com billing. Para manter simples, o app usa uma leitura proporcional do prompt em relação ao contexto total estimado.</p>
        <div class="helpgrid">
            <div class="helpitem"><b>Por hora</b><span>Mostramos uma referência do peso do prompt no contexto estimado. Isso ajuda a ter ideia de quanto daquele orçamento vai embora numa execução.</span></div>
            <div class="helpitem"><b>Plano econômico</b><span>O comparativo já aponta qual modelo e qual velocidade gastam menos, então você decide pelo menor custo sem abrir menu demais.</span></div>
            <div class="helpitem"><b>Limite real</b><span>Se depois você quiser saldo real, aí a conversa muda para autenticação e billing. Por enquanto, a estimativa proporcional resolve bem.</span></div>
        </div>
        <h3 style="margin:18px 0 10px;">Prompts para testar modelos diferentes</h3>
        <div class="helpgrid">
            <?php foreach (example_prompts() as $item): ?>
                <div class="helpitem">
                    <b><?php echo htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8'); ?></b>
                    <span><?php echo htmlspecialchars($item['prompt'], ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="results" id="results"><div class="card empty">Cole o prompt e a URL do repositório para ver a recomendação automática e a tabela comparativa.</div></section>
</div>
<script>
const $ = (id) => document.getElementById(id);
const fmt = new Intl.NumberFormat('pt-BR');
$('helpBtn').addEventListener('click', () => $('helpBox').classList.toggle('open'));
$('analyzeBtn').addEventListener('click', analyze);

async function analyze() {
    const btn = $('analyzeBtn');
    const loading = $('loadingState');
    btn.disabled = true;
    btn.textContent = 'Analisando...';
    loading.classList.add('open', 'pulse');
    setLoading(8, 'Preparando a leitura do repositório...');
    $('results').innerHTML = `<div class="card empty">Lendo o repositório, entendendo o prompt e escolhendo o caminho mais barato.</div>`;
    try {
        setLoading(24, 'Consultando a árvore de arquivos...');
        const response = await fetch('?action=analyze', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                repo_url: $('repo').value.trim(),
                prompt: $('prompt').value.trim(),
                github_token: ''
            })
        });
        setLoading(64, 'Estimando contexto, complexidade e custo...');
        const data = await response.json();
        if (!data.ok) throw new Error(data.error || 'Erro desconhecido.');
        setLoading(90, 'Montando o comparativo final...');
        render(data.result);
        setLoading(100, 'Resultado pronto.');
    } catch (err) {
        $('results').innerHTML = `<div class="card panel"><div class="badge red">Erro</div><p>${escapeHtml(err.message)}</p></div>`;
        setLoading(100, 'Falha ao carregar o resultado.');
    } finally {
        btn.disabled = false;
        btn.textContent = 'Analisar custo mínimo';
        setTimeout(() => {
            loading.classList.remove('open', 'pulse');
            setLoading(0, 'Pronto para analisar.');
        }, 400);
    }
}

function setLoading(percent, text) {
    const value = Math.max(0, Math.min(100, percent));
    $('loadingBar').style.width = `${value}%`;
    $('loadingPercent').textContent = `${value}%`;
    $('loadingText').textContent = text;
}

function render(r) {
    const rec = r.recommendation || {};
    const riskClass = rec.risk === 'alto' ? 'red' : (rec.risk === 'médio' ? 'yellow' : 'green');
    const normal = (r.comparisons || []).filter(x => x.scenario === 'realista' && x.speed === 'normal').sort((a, b) => a.credits - b.credits);
    const allRows = (r.comparisons || []).filter(x => x.scenario === 'realista').sort((a, b) => a.credits - b.credits);
    const maxCredits = Math.max(...allRows.map(x => x.credits), 1);
    const directRows = normal.map(c => `<tr><td><b>${escapeHtml(c.model)}</b></td><td class="right">${fmt.format(c.total_tokens)}</td><td class="right"><b>${fmt.format(c.credits)}</b></td></tr>`).join('');
    const compareRows = allRows.map(c => `<tr><td><b>${escapeHtml(c.model)}</b></td><td>${labelSpeed(c.speed)}</td><td class="right">${fmt.format(c.input_tokens)}</td><td class="right">${fmt.format(c.output_tokens)}</td><td class="right"><b>${fmt.format(c.credits)}</b></td><td><div class="bar"><i style="width:${Math.min(100, (c.credits / maxCredits) * 100)}%"></i></div></td></tr>`).join('');
    $('results').innerHTML = `
        <div class="card panel">
            <div class="recommend">
                <div>
                    <div class="badge ${riskClass}">risco ${escapeHtml(rec.risk || 'baixo')}</div>
                    <h2 style="margin:10px 0 6px;">${escapeHtml(rec.recommended_model || 'GPT-5.4-mini')}</h2>
                    <p class="muted" style="margin:0;">${escapeHtml(rec.reason || '')}</p>
                </div>
                <div style="display:flex;flex-direction:column;gap:8px;align-items:flex-end;">
                    <div class="badge green">${escapeHtml((r.task_type && r.task_type.type) || 'general')}</div>
                    <div class="badge">${Math.round(((r.task_type && r.task_type.confidence) || 0) * 100)}% confiança</div>
                </div>
            </div>
        </div>
        <div class="summary">
            <div class="metric"><small>Prompt</small><b>${fmt.format(r.prompt_tokens || 0)}</b><small>tokens estimados</small></div>
            <div class="metric"><small>Contexto</small><b>${fmt.format(r.input_tokens_direct || 0)}</b><small>prompt + arquivos</small></div>
            <div class="metric"><small>Saída prevista</small><b>${fmt.format(r.output_tokens_estimated || 0)}</b><small>resposta/diff estimado</small></div>
            <div class="metric"><small>Tipo</small><b>${escapeHtml((r.task_type && r.task_type.type) || 'general')}</b><small>detectado automaticamente</small></div>
        </div>
        <div class="card panel">
            <h2>Comparativo rápido em velocidade normal</h2>
            <div class="scroll">
                <table>
                    <thead><tr><th>Modelo</th><th class="right">Tokens totais</th><th class="right">Créditos realistas</th></tr></thead>
                    <tbody>${directRows}</tbody>
                </table>
            </div>
        </div>
        <div class="card panel">
            <h2>Comparativo por modelo + velocidade</h2>
            <div class="scroll">
                <table>
                    <thead><tr><th>Modelo</th><th>Velocidade</th><th class="right">Entrada</th><th class="right">Saída</th><th class="right">Créditos</th><th>peso</th></tr></thead>
                    <tbody>${compareRows}</tbody>
                </table>
            </div>
        </div>
        <div class="card panel">
            <h2>Resumo por hora</h2>
            <p class="footer-note" style="font-size:14px;color:#d9deea;margin-top:0;">${escapeHtml(r.hourly_context_note || '')}</p>
            <p class="footer-note">${escapeHtml(r.accuracy_note || '')}</p>
        </div>
    `;
}

function labelSpeed(speed) {
    if (speed === 'normal') return '<span class="badge green">Normal</span>';
    if (speed === 'fast') return '<span class="badge yellow">Fast</span>';
    return '<span class="badge red">Turbo</span>';
}

function escapeHtml(str) {
    return String(str ?? '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));
}
</script>
</body>
</html>
