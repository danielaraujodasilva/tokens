<?php
/**
 * Token Miser - Estimador pré-play de consumo para Codex
 * Arquivo único: coloque em uma pasta do seu servidor PHP/XAMPP e acesse pelo navegador.
 *
 * Observação honesta: isto estima. Não prevê tool calls, loops internos, cache real ou o que
 * o agente vai decidir abrir depois. Mas já evita muita queima de token por prompt aberto demais.
 */

if (isset($_GET['action']) && $_GET['action'] === 'analyze') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) {
            throw new Exception('JSON inválido.');
        }

        $result = analyze_repo($input);
        echo json_encode(['ok' => true, 'result' => $result], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
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
    $branch = trim($input['branch'] ?? '');
    $taskSize = $input['task_size'] ?? 'medium';
    $maxFiles = max(3, min(60, intval($input['max_files'] ?? 18)));
    $includeAgents = !empty($input['include_agents']);
    $githubToken = trim($input['github_token'] ?? '');

    if ($repoUrl === '') throw new Exception('Informe a URL do repositório GitHub.');
    if ($prompt === '') throw new Exception('Escreva o prompt da tarefa.');

    [$owner, $repo] = parse_github_repo($repoUrl);
    $headers = ['User-Agent: Token-Miser/1.0', 'Accept: application/vnd.github+json'];
    if ($githubToken !== '') {
        $headers[] = 'Authorization: Bearer ' . $githubToken;
    }

    $repoMeta = github_get_json("https://api.github.com/repos/$owner/$repo", $headers);
    if ($branch === '') {
        $branch = $repoMeta['default_branch'] ?? 'main';
    }

    $tree = github_get_json("https://api.github.com/repos/$owner/$repo/git/trees/" . rawurlencode($branch) . "?recursive=1", $headers);
    if (empty($tree['tree'])) throw new Exception('Não consegui ler a árvore do repositório. Verifique repo/branch/token.');

    $allFiles = [];
    foreach ($tree['tree'] as $item) {
        if (($item['type'] ?? '') !== 'blob') continue;
        $path = $item['path'] ?? '';
        $size = intval($item['size'] ?? 0);
        if (!is_candidate_text_file($path, $size)) continue;
        $score = score_file($path, $prompt, $includeAgents);
        if ($score <= 0 && !$includeAgents) continue;

        $allFiles[] = [
            'path' => $path,
            'sha' => $item['sha'] ?? '',
            'size' => $size,
            'score' => $score,
        ];
    }

    usort($allFiles, function($a, $b) {
        if ($b['score'] === $a['score']) return $a['size'] <=> $b['size'];
        return $b['score'] <=> $a['score'];
    });

    $selected = array_slice($allFiles, 0, $maxFiles);

    if ($includeAgents) {
        foreach ($allFiles as $file) {
            if (preg_match('~(^|/)AGENTS\.md$~i', $file['path'])) {
                $exists = false;
                foreach ($selected as $s) {
                    if ($s['path'] === $file['path']) $exists = true;
                }
                if (!$exists) array_unshift($selected, $file);
                break;
            }
        }
    }

    $fileRows = [];
    $fileTokens = 0;
    $fetchLimitBytes = 900000;

    foreach ($selected as $file) {
        if ($file['size'] > $fetchLimitBytes) {
            $estimated = approximate_tokens_from_length($file['size']);
            $fileRows[] = [
                'path' => $file['path'],
                'tokens' => $estimated,
                'size' => $file['size'],
                'score' => $file['score'],
                'fetched' => false,
                'note' => 'Arquivo grande: estimado por tamanho, não baixado completo.',
            ];
            $fileTokens += $estimated;
            continue;
        }

        $content = fetch_blob_text($owner, $repo, $file['sha'], $headers);
        $tokens = estimate_tokens($content);
        $fileTokens += $tokens;
        $fileRows[] = [
            'path' => $file['path'],
            'tokens' => $tokens,
            'size' => $file['size'],
            'score' => $file['score'],
            'fetched' => true,
            'note' => '',
        ];
    }

    $promptTokens = estimate_tokens($prompt);
    $outputTokens = estimate_output_tokens($taskSize, $prompt);
    $inputTokens = $promptTokens + $fileTokens;

    $rates = codex_rates();
    $speedMultipliers = [
        'normal' => floatval($input['speed_normal'] ?? 1.0),
        'fast' => floatval($input['speed_fast'] ?? 1.5),
        'turbo' => floatval($input['speed_turbo'] ?? 2.0),
    ];

    $scenarios = [
        'direto' => 1.00,
        'realista' => 1.30,
        'pessimista' => 1.70,
    ];

    $comparisons = [];
    foreach ($rates as $model => $rate) {
        foreach ($speedMultipliers as $speed => $multiplier) {
            foreach ($scenarios as $scenario => $scenarioMultiplier) {
                $scenarioInput = intval(ceil($inputTokens * $scenarioMultiplier));
                $scenarioOutput = intval(ceil($outputTokens * ($scenario === 'pessimista' ? 1.25 : ($scenario === 'realista' ? 1.10 : 1.00))));
                $credits = estimate_credits($scenarioInput, 0, $scenarioOutput, $rate, $multiplier);
                $comparisons[] = [
                    'model' => $model,
                    'speed' => $speed,
                    'scenario' => $scenario,
                    'input_tokens' => $scenarioInput,
                    'output_tokens' => $scenarioOutput,
                    'total_tokens' => $scenarioInput + $scenarioOutput,
                    'credits' => round($credits, 4),
                    'multiplier' => $multiplier,
                ];
            }
        }
    }

    usort($comparisons, fn($a, $b) => $a['credits'] <=> $b['credits']);

    $recommendation = build_recommendation($comparisons, $inputTokens, $taskSize, $selected, $prompt);
    $optimizedPrompt = build_optimized_prompt($prompt, $selected, $recommendation['recommended_model'] ?? 'GPT-5.4-mini');

    return [
        'repo' => "$owner/$repo",
        'branch' => $branch,
        'prompt_tokens' => $promptTokens,
        'file_tokens' => $fileTokens,
        'input_tokens_direct' => $inputTokens,
        'output_tokens_estimated' => $outputTokens,
        'selected_files' => $fileRows,
        'total_candidate_files' => count($allFiles),
        'comparisons' => $comparisons,
        'recommendation' => $recommendation,
        'optimized_prompt' => $optimizedPrompt,
        'rates_source_note' => 'Taxas editáveis no código. Baseadas no rate card público do Codex consultado em 2026-05-20.',
        'accuracy_note' => 'Estimativa: não inclui loops internos, comandos de terminal, arquivos abertos depois pelo agente, MCP servers, cache real ou retries.',
    ];
}

function parse_github_repo(string $url): array
{
    $url = trim($url);

    if (preg_match('~github\.com[:/]+([^/\s]+)/([^/\s#?]+)~i', $url, $m)) {
        return [$m[1], preg_replace('~\.git$~', '', $m[2])];
    }

    if (preg_match('~^([^/\s]+)/([^/\s]+)$~', $url, $m)) {
        return [$m[1], preg_replace('~\.git$~', '', $m[2])];
    }

    throw new Exception('URL inválida. Use algo como https://github.com/usuario/repositorio ou usuario/repositorio.');
}

function github_get_json(string $url, array $headers): array
{
    $body = http_get($url, $headers);
    $data = json_decode($body, true);
    if (!is_array($data)) {
        throw new Exception('Resposta inválida da API do GitHub.');
    }
    if (!empty($data['message']) && isset($data['documentation_url'])) {
        throw new Exception('GitHub API: ' . $data['message']);
    }
    return $data;
}

function http_get(string $url, array $headers): string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        $body = curl_exec($ch);
        $err = curl_error($ch);
        $code = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
        curl_close($ch);

        if ($body === false || $code >= 400) {
            throw new Exception("Erro ao acessar GitHub ($code): " . ($err ?: substr((string)$body, 0, 300)));
        }
        return $body;
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => implode("\r\n", $headers),
            'timeout' => 30,
        ]
    ]);
    $body = @file_get_contents($url, false, $context);
    if ($body === false) throw new Exception('Erro ao acessar GitHub. Ative cURL no PHP para melhores resultados.');
    return $body;
}

function fetch_blob_text(string $owner, string $repo, string $sha, array $headers): string
{
    $blob = github_get_json("https://api.github.com/repos/$owner/$repo/git/blobs/$sha", $headers);
    $encoding = $blob['encoding'] ?? '';
    $content = $blob['content'] ?? '';

    if ($encoding === 'base64') {
        return base64_decode($content) ?: '';
    }

    return (string)$content;
}

function is_candidate_text_file(string $path, int $size): bool
{
    $lower = strtolower($path);

    $ignoreDirs = [
        '.git/', 'node_modules/', 'vendor/', 'dist/', 'build/', '.next/', 'storage/',
        'logs/', 'cache/', 'tmp/', 'uploads/', 'backup/', 'backups/', '__pycache__/',
    ];
    foreach ($ignoreDirs as $dir) {
        if (str_contains($lower, $dir)) return false;
    }

    $ignoreNames = [
        'package-lock.json', 'composer.lock', 'yarn.lock', 'pnpm-lock.yaml',
    ];
    if (in_array(basename($lower), $ignoreNames, true)) return false;

    $binaryExt = [
        'png','jpg','jpeg','gif','webp','ico','pdf','zip','rar','7z','gz','tar',
        'mp4','mov','avi','mp3','wav','ttf','otf','woff','woff2','exe','dll','bin',
        'sqlite','db','psd','ai','sketch'
    ];
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (in_array($ext, $binaryExt, true)) return false;

    $textExt = [
        'php','js','ts','tsx','jsx','css','scss','html','htm','json','md','txt','sql',
        'py','yml','yaml','xml','env','example','ini','conf','sh','bat','ps1','lock',
        'vue','svelte','cjs','mjs','htaccess'
    ];

    if (preg_match('~(^|/)AGENTS\.md$~i', $path)) return true;
    if (preg_match('~(^|/)\.env\.example$~i', $path)) return true;
    if ($size <= 0) return false;
    if ($size > 1500000) return false;

    return in_array($ext, $textExt, true) || basename($path) === '.htaccess';
}

function score_file(string $path, string $prompt, bool $includeAgents): int
{
    $p = mb_strtolower($prompt, 'UTF-8');
    $pathLower = mb_strtolower($path, 'UTF-8');
    $base = mb_strtolower(basename($path), 'UTF-8');
    $score = 0;

    if ($includeAgents && preg_match('~(^|/)agents\.md$~i', $pathLower)) $score += 1000;

    $keywords = extract_keywords($p);
    foreach ($keywords as $kw) {
        if (mb_strlen($kw, 'UTF-8') < 3) continue;
        if (str_contains($pathLower, $kw)) $score += 12;
        if (str_contains($base, $kw)) $score += 18;
    }

    $maps = [
        'crm' => ['crm/', 'lead', 'pipeline', 'kanban', 'handler', 'configuracoes', 'index.php'],
        'lead' => ['crm/', 'lead', 'handler', 'pipeline', 'index.php'],
        'modal' => ['modal', 'index.php', '.js', 'bootstrap'],
        'botao' => ['button', 'btn', 'index.php', '.js', '.css'],
        'formulario' => ['form', 'handler', 'submit', 'index.php'],
        'whatsapp' => ['whatsapp', 'baileys', 'venom', 'bot', 'zap'],
        'baileys' => ['baileys', 'whatsapp', 'bot', 'session'],
        'webhook' => ['webhook', 'deploy', 'github', 'handler'],
        'login' => ['login', 'auth', 'senha', 'usuario', 'user'],
        'css' => ['.css', 'style', 'tailwind', 'bootstrap'],
        'javascript' => ['.js', 'script', 'assets/js'],
        'php' => ['.php'],
        'banco' => ['sql', 'db', 'database', 'mysql', 'pdo', 'mysqli'],
        'mysql' => ['sql', 'db', 'database', 'mysql', 'pdo', 'mysqli'],
        'ficha' => ['ficha/'],
        'orcamento' => ['orcamento/', 'budget', 'quote'],
        'zap' => ['zap/', 'analisador', 'whatsapp'],
    ];

    foreach ($maps as $trigger => $needles) {
        if (str_contains($p, $trigger)) {
            foreach ($needles as $needle) {
                if (str_contains($pathLower, $needle)) $score += 20;
            }
        }
    }

    foreach (['index.php','handler.php','config.php','configuracoes.php','app.js','main.js','style.css','package.json'] as $central) {
        if ($base === $central) $score += 8;
    }

    if (str_contains($pathLower, 'readme')) $score -= 6;
    if (str_contains($pathLower, 'mock') || str_contains($pathLower, 'sample')) $score -= 4;

    return max(0, $score);
}

function extract_keywords(string $prompt): array
{
    preg_match_all('/[\p{L}\p{N}_-]{3,}/u', $prompt, $matches);
    $words = $matches[0] ?? [];
    $stop = array_flip([
        'para','com','que','uma','por','dos','das','nos','nas','esse','essa','isso','aquele',
        'aquela','corrija','crie','faça','faca','alterar','altere','ajuste','usar','use',
        'somente','arquivo','arquivos','projeto','repositorio','repositório','preciso','quero',
        'codex','token','tokens','github','branch','main','master','the','and','for','with'
    ]);
    $out = [];
    foreach ($words as $w) {
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
    $nonSpaceCount = count($nonSpace[0] ?? []);
    $byChars = $len / 3.6;
    $byNonSpace = $nonSpaceCount / 2.9;

    return (int)ceil(max($byChars, $byNonSpace));
}

function approximate_tokens_from_length(int $bytes): int
{
    return (int)ceil($bytes / 3.6);
}

function estimate_output_tokens(string $taskSize, string $prompt): int
{
    $map = [
        'tiny' => 1200,
        'small' => 2200,
        'medium' => 4200,
        'large' => 7600,
        'diagnostic' => 3000,
        'review' => 5200,
    ];

    $base = $map[$taskSize] ?? 4200;
    $p = mb_strtolower($prompt, 'UTF-8');

    if (str_contains($p, 'explique') || str_contains($p, 'detalhado') || str_contains($p, 'documente')) {
        $base = (int)ceil($base * 1.35);
    }

    if (str_contains($p, 'resumo curto') || str_contains($p, 'sem explicação') || str_contains($p, 'sem explicacao')) {
        $base = (int)ceil($base * 0.65);
    }

    return $base;
}

function codex_rates(): array
{
    return [
        'GPT-5.4-mini' => ['input' => 18.75, 'cached' => 1.875, 'output' => 113],
        'GPT-5.3-Codex' => ['input' => 43.75, 'cached' => 4.375, 'output' => 350],
        'GPT-5.4' => ['input' => 62.50, 'cached' => 6.250, 'output' => 375],
        'GPT-5.5' => ['input' => 125.00, 'cached' => 12.50, 'output' => 750],
    ];
}

function estimate_credits(int $inputTokens, int $cachedTokens, int $outputTokens, array $rate, float $speedMultiplier): float
{
    $credits =
        ($inputTokens / 1000000) * $rate['input'] +
        ($cachedTokens / 1000000) * $rate['cached'] +
        ($outputTokens / 1000000) * $rate['output'];

    return $credits * max(0.1, $speedMultiplier);
}

function build_recommendation(array $comparisons, int $inputTokens, string $taskSize, array $selected, string $prompt): array
{
    $directNormal = array_values(array_filter($comparisons, fn($c) => $c['scenario'] === 'realista' && $c['speed'] === 'normal'));
    usort($directNormal, fn($a, $b) => $a['credits'] <=> $b['credits']);

    $recommended = 'GPT-5.4-mini';
    $reason = 'Contexto pequeno/médio. Use o mini e mantenha escopo fechado.';
    $risk = 'baixo';

    if ($inputTokens > 120000 || in_array($taskSize, ['large', 'review'], true)) {
        $recommended = 'GPT-5.3-Codex';
        $reason = 'Contexto maior ou tarefa entre vários arquivos. Melhor usar Codex dedicado e evitar GPT-5.5 salvo diagnóstico.';
        $risk = 'médio';
    }

    if ($inputTokens > 260000) {
        $recommended = 'GPT-5.3-Codex';
        $reason = 'Contexto grande. Quebre a tarefa antes de rodar, mesmo com GPT-5.3-Codex.';
        $risk = 'alto';
    }

    $p = mb_strtolower($prompt, 'UTF-8');
    if (str_contains($p, 'não sei') || str_contains($p, 'nao sei') || str_contains($p, 'descubra') || str_contains($p, 'investigue')) {
        $recommended = 'GPT-5.5 para diagnóstico, depois GPT-5.4-mini para aplicar';
        $reason = 'Prompt investigativo. Use modelo forte só para diagnosticar, sem alterar arquivos, e depois aplique com modelo barato.';
        $risk = $inputTokens > 120000 ? 'alto' : 'médio';
    }

    $best = $directNormal[0] ?? null;

    return [
        'recommended_model' => $recommended,
        'risk' => $risk,
        'reason' => $reason,
        'best_cheapest_realistic_normal' => $best,
        'selected_file_count' => count($selected),
    ];
}

function build_optimized_prompt(string $prompt, array $selected, string $model): string
{
    $paths = array_slice(array_map(fn($f) => $f['path'], $selected), 0, 18);
    $list = '';
    foreach ($paths as $p) {
        $list .= "- $p\n";
    }

    return trim("Use {$model}.\n\nTarefa:\n{$prompt}\n\nEscopo sugerido:\nLeia somente estes arquivos primeiro:\n{$list}\nRegras:\n- Use o AGENTS.md se existir.\n- Não leia o projeto inteiro.\n- Não refatore sem pedido explícito.\n- Faça o menor diff possível.\n- Não altere arquivos fora do escopo sem justificar antes.\n- Ao final, responda apenas com: arquivos alterados, resumo curto e como testar.");
}
?>
<!doctype html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <title>Token Miser - Estimador pré-play do Codex</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        :root{--bg:#09090d;--panel:#11111a;--panel2:#171724;--muted:#9ba0ad;--text:#f5f7fb;--line:rgba(255,255,255,.10);--red:#ff3b5f;--red2:#b91434;--green:#36d399;--yellow:#fbbf24;--blue:#60a5fa;--purple:#a78bfa;--shadow:0 24px 70px rgba(0,0,0,.45);--radius:22px}
        *{box-sizing:border-box}
        body{margin:0;min-height:100vh;background:radial-gradient(circle at 16% -10%, rgba(255,59,95,.26), transparent 35%),radial-gradient(circle at 90% 10%, rgba(96,165,250,.18), transparent 30%),linear-gradient(135deg,#07070a,#11111a 45%,#09090d);color:var(--text);font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
        a{color:var(--blue)}.wrap{max-width:1260px;margin:0 auto;padding:32px 18px 70px}.hero{display:grid;grid-template-columns:1.15fr .85fr;gap:22px;align-items:stretch;margin-bottom:22px}.card{background:linear-gradient(180deg,rgba(255,255,255,.06),rgba(255,255,255,.025));border:1px solid var(--line);border-radius:var(--radius);box-shadow:var(--shadow);backdrop-filter:blur(10px)}.hero-main{padding:34px}.eyebrow{display:inline-flex;align-items:center;gap:8px;color:#ffd3dc;border:1px solid rgba(255,59,95,.35);background:rgba(255,59,95,.10);border-radius:999px;padding:7px 12px;font-size:13px;font-weight:700;letter-spacing:.2px}h1{font-size:clamp(32px,4vw,56px);line-height:1.02;margin:18px 0 14px}.lead{color:#c8ccd8;font-size:18px;line-height:1.55;margin:0;max-width:770px}.hero-side{padding:24px;display:flex;flex-direction:column;justify-content:space-between;gap:14px}.mini-stat{background:rgba(0,0,0,.22);border:1px solid var(--line);border-radius:18px;padding:16px}.mini-stat b{display:block;font-size:26px;margin-bottom:4px}.mini-stat span{color:var(--muted);font-size:13px}.grid{display:grid;grid-template-columns:450px 1fr;gap:22px;align-items:start}.form{padding:22px;position:sticky;top:16px}label{display:block;font-weight:800;font-size:13px;margin:16px 0 8px;color:#e8eaf1}input,textarea,select{width:100%;border:1px solid var(--line);background:#0b0b12;color:var(--text);border-radius:16px;padding:13px 14px;outline:none;font:inherit;transition:.18s border,.18s transform,.18s box-shadow}input:focus,textarea:focus,select:focus{border-color:rgba(255,59,95,.75);box-shadow:0 0 0 4px rgba(255,59,95,.12)}textarea{min-height:210px;resize:vertical;line-height:1.45}.row{display:grid;grid-template-columns:1fr 1fr;gap:12px}.triple{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px}.check{display:flex;align-items:center;gap:10px;margin-top:14px;color:#dce0ea;font-size:14px}.check input{width:auto}.hint{color:var(--muted);font-size:12px;line-height:1.4;margin-top:7px}.btn{border:0;cursor:pointer;width:100%;margin-top:20px;border-radius:18px;padding:15px 18px;color:white;font-weight:900;letter-spacing:.2px;background:linear-gradient(135deg,var(--red),#7c3aed);box-shadow:0 14px 35px rgba(255,59,95,.22);transition:.18s transform,.18s opacity}.btn:hover{transform:translateY(-1px)}.btn:disabled{opacity:.6;cursor:not-allowed;transform:none}.results{display:flex;flex-direction:column;gap:18px}.empty{padding:34px;text-align:center;color:var(--muted)}.summary{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}.metric{padding:18px;border-radius:18px;background:rgba(0,0,0,.25);border:1px solid var(--line)}.metric small{display:block;color:var(--muted);font-size:12px}.metric b{font-size:24px;display:block;margin-top:6px}.panel{padding:22px}.panel h2{margin:0 0 14px;font-size:22px}.badge{display:inline-flex;border-radius:999px;padding:7px 11px;font-size:12px;font-weight:900;border:1px solid var(--line);background:rgba(255,255,255,.06)}.badge.green{color:#b9f8dc;background:rgba(54,211,153,.12);border-color:rgba(54,211,153,.25)}.badge.yellow{color:#fff1b8;background:rgba(251,191,36,.11);border-color:rgba(251,191,36,.25)}.badge.red{color:#ffd0d8;background:rgba(255,59,95,.12);border-color:rgba(255,59,95,.25)}.recommend{display:grid;grid-template-columns:1fr auto;gap:12px;align-items:center;padding:20px;border-radius:20px;background:radial-gradient(circle at 10% 10%,rgba(54,211,153,.16),transparent 28%),rgba(0,0,0,.28);border:1px solid var(--line)}.recommend h3{margin:0 0 5px}.recommend p{margin:0;color:#cbd0dc;line-height:1.45}table{width:100%;border-collapse:collapse;overflow:hidden}th,td{padding:12px 10px;border-bottom:1px solid var(--line);text-align:left;font-size:14px}th{color:#dce0ea;font-size:12px;text-transform:uppercase;letter-spacing:.06em;background:rgba(255,255,255,.04)}td{color:#eef1f7}tr:hover td{background:rgba(255,255,255,.025)}.scroll{overflow:auto;border:1px solid var(--line);border-radius:18px}.right{text-align:right}.muted{color:var(--muted)}.bar{height:9px;border-radius:999px;background:rgba(255,255,255,.08);overflow:hidden;min-width:110px}.bar i{display:block;height:100%;border-radius:999px;background:linear-gradient(90deg,var(--green),var(--yellow),var(--red))}.copybox{width:100%;min-height:220px;white-space:pre-wrap;background:#07070b;border:1px solid var(--line);border-radius:18px;padding:16px;color:#e8eaf1;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:13px;line-height:1.5}.copybtn{display:inline-flex;border:1px solid var(--line);background:rgba(255,255,255,.08);color:white;border-radius:999px;padding:8px 12px;cursor:pointer;font-weight:800;margin-bottom:10px}.error{padding:16px;border-radius:18px;background:rgba(255,59,95,.12);border:1px solid rgba(255,59,95,.30);color:#ffd7de}.footer-note{color:var(--muted);font-size:12px;line-height:1.45;margin-top:16px}@media(max-width:980px){.hero,.grid{grid-template-columns:1fr}.form{position:static}.summary{grid-template-columns:1fr 1fr}}@media(max-width:560px){.row,.triple,.summary{grid-template-columns:1fr}.hero-main{padding:24px}}
    </style>
</head>
<body>
<div class="wrap">
    <section class="hero">
        <div class="card hero-main">
            <div class="eyebrow">🧮 Token Miser · pré-play do Codex</div>
            <h1>Descubra se vale dar play antes do Codex comer seu limite.</h1>
            <p class="lead">Cole o prompt, informe o repositório do GitHub e receba uma estimativa comparando modelos, velocidades, arquivos prováveis e risco de consumo. Não é bola de cristal, mas já é melhor que clicar em “run” e assistir seus tokens virarem fumaça com crachá.</p>
        </div>
        <div class="card hero-side">
            <div class="mini-stat"><b>4 modelos</b><span>GPT-5.4-mini, GPT-5.3-Codex, GPT-5.4 e GPT-5.5</span></div>
            <div class="mini-stat"><b>3 cenários</b><span>direto, realista e pessimista</span></div>
            <div class="mini-stat"><b>1 prompt melhorado</b><span>com escopo sugerido para copiar no Codex</span></div>
        </div>
    </section>
    <main class="grid">
        <section class="card form">
            <label for="repo">Repositório GitHub</label><input id="repo" placeholder="https://github.com/danielaraujodasilva/tatuagem" value="">
            <div class="row"><div><label for="branch">Branch</label><input id="branch" placeholder="vazio = branch padrão"></div><div><label for="taskSize">Tipo de tarefa</label><select id="taskSize"><option value="tiny">Micro ajuste</option><option value="small">Pequena</option><option value="medium" selected>Média</option><option value="large">Grande</option><option value="diagnostic">Diagnóstico</option><option value="review">Review / PR</option></select></div></div>
            <label for="prompt">Prompt que você pretende mandar</label><textarea id="prompt" placeholder="Ex: No CRM, corrija o modal de Novo Lead que não abre. Use o AGENTS.md. Não refatore."></textarea>
            <div class="row"><div><label for="maxFiles">Máximo de arquivos analisados</label><input id="maxFiles" type="number" min="3" max="60" value="18"><div class="hint">Mais arquivos = estimativa mais conservadora, mas mais lenta.</div></div><div><label for="githubToken">GitHub token opcional</label><input id="githubToken" type="password" placeholder="só se repo privado ou rate limit"><div class="hint">Não salva nada. Vai só nesta requisição.</div></div></div>
            <label>Multiplicadores de velocidade</label><div class="triple"><input id="speedNormal" type="number" step="0.1" value="1.0" title="Normal"><input id="speedFast" type="number" step="0.1" value="1.5" title="Fast"><input id="speedTurbo" type="number" step="0.1" value="2.0" title="Turbo"></div>
            <div class="hint">Como a taxa exata de Speed/Fast pode mudar, deixei editável. Normal = 1x. Fast/Turbo são multiplicadores práticos.</div>
            <label class="check"><input id="includeAgents" type="checkbox" checked>Incluir AGENTS.md quando existir</label>
            <button class="btn" id="analyzeBtn">Analisar antes de dar play</button>
            <div class="footer-note">Estima tokens por heurística local em PHP. Para máxima precisão, no futuro dá para trocar o contador por tiktoken via Python/Node no servidor.</div>
        </section>
        <section class="results" id="results"><div class="card empty">Preencha o prompt e o repo. A análise aparece aqui, sem drama, sem fogo no limite, sem Codex saindo para “entender o projeto” como quem vai comprar cigarro e volta com uma refatoração.</div></section>
    </main>
</div>
<script>
const $ = (id) => document.getElementById(id);
const fmt = new Intl.NumberFormat('pt-BR');
$('analyzeBtn').addEventListener('click', analyze);
async function analyze(){
    const btn = $('analyzeBtn');
    const results = $('results');
    btn.disabled = true;
    btn.textContent = 'Analisando repo e estimando tokens...';
    results.innerHTML = `<div class="card empty">Buscando árvore do GitHub, escolhendo arquivos prováveis e fazendo a continha que as plataformas fingem que não seria útil. Segura essa ansiedade.</div>`;
    const payload = {
        repo_url: $('repo').value.trim(),
        branch: $('branch').value.trim(),
        prompt: $('prompt').value.trim(),
        task_size: $('taskSize').value,
        max_files: parseInt($('maxFiles').value || '18', 10),
        github_token: $('githubToken').value.trim(),
        include_agents: $('includeAgents').checked,
        speed_normal: parseFloat($('speedNormal').value || '1'),
        speed_fast: parseFloat($('speedFast').value || '1.5'),
        speed_turbo: parseFloat($('speedTurbo').value || '2'),
    };
    try{
        const response = await fetch('?action=analyze', {method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify(payload)});
        const data = await response.json();
        if(!data.ok) throw new Error(data.error || 'Erro desconhecido.');
        render(data.result);
    }catch(err){
        results.innerHTML = `<div class="card panel"><div class="error"><b>Deu ruim:</b><br>${escapeHtml(err.message)}</div></div>`;
    }finally{
        btn.disabled = false;
        btn.textContent = 'Analisar antes de dar play';
    }
}
function render(r){
    const rec = r.recommendation || {};
    const riskClass = rec.risk === 'alto' ? 'red' : (rec.risk === 'médio' ? 'yellow' : 'green');
    const realisticNormal = r.comparisons.filter(x => x.scenario === 'realista' && x.speed === 'normal').sort((a,b) => a.credits - b.credits);
    const allRows = r.comparisons.filter(x => x.scenario === 'realista').sort((a,b) => a.credits - b.credits);
    const maxCredits = Math.max(...allRows.map(x => x.credits), 1);
    const fileRows = r.selected_files.sort((a,b) => b.tokens - a.tokens).map(f => `<tr><td><code>${escapeHtml(f.path)}</code><div class="muted">${escapeHtml(f.note || '')}</div></td><td class="right">${fmt.format(f.tokens)}</td><td class="right">${fmt.format(f.size)} B</td><td class="right">${fmt.format(f.score)}</td></tr>`).join('');
    const compareRows = allRows.map(c => `<tr><td><b>${escapeHtml(c.model)}</b></td><td>${labelSpeed(c.speed)}</td><td class="right">${fmt.format(c.input_tokens)}</td><td class="right">${fmt.format(c.output_tokens)}</td><td class="right"><b>${fmt.format(c.credits)}</b></td><td><div class="bar"><i style="width:${Math.min(100, (c.credits / maxCredits) * 100)}%"></i></div></td></tr>`).join('');
    const directRows = realisticNormal.map(c => `<tr><td><b>${escapeHtml(c.model)}</b></td><td class="right">${fmt.format(c.total_tokens)}</td><td class="right"><b>${fmt.format(c.credits)}</b></td></tr>`).join('');
    $('results').innerHTML = `
        <div class="card panel"><div class="recommend"><div><span class="badge ${riskClass}">risco ${escapeHtml(rec.risk || 'baixo')}</span><h3>Recomendação: ${escapeHtml(rec.recommended_model || 'GPT-5.4-mini')}</h3><p>${escapeHtml(rec.reason || '')}</p></div><div class="badge green">${escapeHtml(r.repo)} · ${escapeHtml(r.branch)}</div></div></div>
        <div class="summary"><div class="metric"><small>Prompt</small><b>${fmt.format(r.prompt_tokens)}</b><small>tokens estimados</small></div><div class="metric"><small>Arquivos prováveis</small><b>${fmt.format(r.file_tokens)}</b><small>tokens estimados</small></div><div class="metric"><small>Entrada direta</small><b>${fmt.format(r.input_tokens_direct)}</b><small>prompt + arquivos</small></div><div class="metric"><small>Saída prevista</small><b>${fmt.format(r.output_tokens_estimated)}</b><small>resposta/diff estimado</small></div></div>
        <div class="card panel"><h2>Comparativo rápido em velocidade normal</h2><div class="scroll"><table><thead><tr><th>Modelo</th><th class="right">Tokens totais</th><th class="right">Créditos realistas</th></tr></thead><tbody>${directRows}</tbody></table></div></div>
        <div class="card panel"><h2>Comparativo por modelo + velocidade</h2><div class="scroll"><table><thead><tr><th>Modelo</th><th>Velocidade</th><th class="right">Entrada</th><th class="right">Saída</th><th class="right">Créditos</th><th>peso</th></tr></thead><tbody>${compareRows}</tbody></table></div><p class="footer-note">Tabela usa o cenário realista: entrada direta + 30% para arquivos extras que o agente pode abrir.</p></div>
        <div class="card panel"><h2>Arquivos prováveis que entram no contexto</h2><div class="scroll"><table><thead><tr><th>Arquivo</th><th class="right">Tokens</th><th class="right">Tamanho</th><th class="right">Score</th></tr></thead><tbody>${fileRows}</tbody></table></div><p class="footer-note">Candidatos encontrados: ${fmt.format(r.total_candidate_files)}. Se a lista parece errada, deixe o prompt mais específico ou reduza/aumente o máximo de arquivos.</p></div>
        <div class="card panel"><h2>Prompt econômico sugerido</h2><button class="copybtn" onclick="copyPrompt()">Copiar prompt</button><pre class="copybox" id="optimizedPrompt">${escapeHtml(r.optimized_prompt)}</pre><p class="footer-note">${escapeHtml(r.accuracy_note)} ${escapeHtml(r.rates_source_note)}</p></div>`;
}
function labelSpeed(speed){if(speed === 'normal') return '<span class="badge green">Normal</span>'; if(speed === 'fast') return '<span class="badge yellow">Fast</span>'; return '<span class="badge red">Turbo</span>';}
function copyPrompt(){navigator.clipboard.writeText($('optimizedPrompt').innerText);}
function escapeHtml(str){return String(str ?? '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));}
</script>
</body>
</html>
