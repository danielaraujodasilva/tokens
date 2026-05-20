<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

function deploy_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function deploy_log(string $message): void
{
    $dir = ROOT_PATH . DIRECTORY_SEPARATOR . 'logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    if (!is_dir($dir) || !is_writable($dir)) {
        return;
    }
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    @file_put_contents($dir . DIRECTORY_SEPARATOR . 'deploy.log', $line, FILE_APPEND);
}

function run_deploy_command(string $command): array
{
    $output = [];
    $code = 0;
    exec($command . ' 2>&1', $output, $code);
    return ['command' => $command, 'code' => $code, 'output' => implode("\n", $output)];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    deploy_response(['success' => false, 'error' => 'Metodo nao permitido.'], 405);
}

if (DEPLOY_WEBHOOK_SECRET === 'troque-este-segredo-no-servidor' || DEPLOY_WEBHOOK_SECRET === '') {
    deploy_log('Webhook recusado: segredo padrao nao alterado.');
    deploy_response(['success' => false, 'error' => 'Configure DEPLOY_WEBHOOK_SECRET no servidor.'], 500);
}

$payload = file_get_contents('php://input') ?: '';
$signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
$expected = 'sha256=' . hash_hmac('sha256', $payload, DEPLOY_WEBHOOK_SECRET);

if (!hash_equals($expected, $signature)) {
    deploy_log('Webhook recusado: assinatura invalida.');
    deploy_response(['success' => false, 'error' => 'Assinatura invalida.'], 401);
}

$event = $_SERVER['HTTP_X_GITHUB_EVENT'] ?? '';
if ($event === 'ping') {
    deploy_log('Ping recebido com sucesso.');
    deploy_response(['success' => true, 'message' => 'Ping recebido.']);
}

if ($event !== 'push') {
    deploy_log('Evento ignorado: ' . $event);
    deploy_response(['success' => true, 'message' => 'Evento ignorado: ' . $event]);
}

$data = json_decode($payload, true);
if (!is_array($data)) {
    deploy_response(['success' => false, 'error' => 'Payload JSON invalido.'], 400);
}

$expectedRef = 'refs/heads/' . DEPLOY_BRANCH;
if (($data['ref'] ?? '') !== $expectedRef) {
    deploy_log('Push ignorado para ref: ' . ($data['ref'] ?? 'sem ref'));
    deploy_response(['success' => true, 'message' => 'Branch ignorada. Esperada: ' . DEPLOY_BRANCH]);
}

if (!is_dir(DEPLOY_REPO_PATH . DIRECTORY_SEPARATOR . '.git')) {
    deploy_log('Falha: DEPLOY_REPO_PATH nao e um repositorio Git: ' . DEPLOY_REPO_PATH);
    deploy_response(['success' => false, 'error' => 'O diretorio do projeto no servidor nao e um repositorio Git.'], 500);
}

$repoPath = escapeshellarg(DEPLOY_REPO_PATH);
$branch = escapeshellarg(DEPLOY_BRANCH);
$git = escapeshellcmd(GIT_PATH);

$commands = [
    "$git -C $repoPath fetch origin $branch",
    "$git -C $repoPath pull --ff-only origin $branch",
];

$results = [];
foreach ($commands as $command) {
    $result = run_deploy_command($command);
    $results[] = $result;
    deploy_log($result['command'] . "\n" . $result['output']);
    if ($result['code'] !== 0) {
        deploy_response(['success' => false, 'error' => 'Deploy falhou.', 'results' => $results], 500);
    }
}

deploy_response([
    'success' => true,
    'message' => 'Servidor atualizado com sucesso.',
    'branch' => DEPLOY_BRANCH,
    'after' => $data['after'] ?? null,
    'results' => $results,
]);
