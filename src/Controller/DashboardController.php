<?php
namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class DashboardController extends AbstractController
{
    private string $projectDir;
    private Filesystem $fs;

    public function __construct()
    {
        $this->projectDir = dirname(__DIR__, 2);
        $this->fs = new Filesystem();
    }

    #[Route('/', name: 'app_dashboard', methods: ['GET'])]
    public function dashboard(): Response
    {
        return $this->render('dashboard.html.twig');
    }

    #[Route('/process/start', name: 'app_process_start', methods: ['POST'])]
    public function start(Request $request): JsonResponse
    {
        $log = $_ENV['LOG_FILE'] ?? ($this->projectDir . '/var/logs/file_processor.log');
        $jobFile = $this->projectDir . '/var/state/job.json';
        $jobsList = $this->projectDir . '/var/state/jobs.json';

        if ($this->fs->exists($log)) {
            $this->fs->remove($log);
        }

        $dryRun = filter_var($request->get('dryRun', 'true'), FILTER_VALIDATE_BOOLEAN);
        $limit = $request->get('limit');
        $resume = filter_var($request->get('resume', 'false'), FILTER_VALIDATE_BOOLEAN);

        $args = ['app:process-files'];
        if ($dryRun) $args[] = '--dry-run';
        if ($limit) $args[] = '--limit=' . $limit;
        if ($resume) $args[] = '--resume';

        $phpPath = PHP_BINARY;
        $cmd = "\"{$phpPath}\" \"{$this->projectDir}/bin/console\" " . implode(' ', $args) . " > " . escapeshellarg($log) . " 2>&1";

        if (PHP_OS_FAMILY === 'Windows') {
            $cmd = "start /min cmd /C \"cd /d {$this->projectDir} && {$cmd}\"";
            pclose(popen($cmd, 'r'));
        } else {
            exec("cd {$this->projectDir} && {$cmd} &");
        }

        $job = [
            'started_at' => date('c'),
            'args' => $args,
            'status' => 'running',
            'env' => $this->exportRelevantEnv(),
        ];

        $this->fs->dumpFile($jobFile, json_encode($job, JSON_PRETTY_PRINT));

        $jobs = $this->fs->exists($jobsList)
            ? json_decode(file_get_contents($jobsList), true)
            : [];
        $jobs[] = $job;
        $this->fs->dumpFile($jobsList, json_encode($jobs, JSON_PRETTY_PRINT));

        return new JsonResponse(['status' => 'started', 'args' => $args]);
    }

    #[Route('/process/status', name: 'app_process_status')]
    public function status(): JsonResponse
    {
        $checkpoint = $this->readJson($_ENV['CHECKPOINT_FILE'] ?? '/var/state/checkpoint.json');
        $job = $this->readJson('/var/state/job.json');
        $log = $this->readFile($_ENV['LOG_FILE'] ?? '/var/logs/file_processor.log');

        $percent = 0;
        $errors = substr_count($log, 'ERRORE');
        $lastRecord = $checkpoint['last_id'] ?? null;
        $total = $checkpoint['total'] ?? 0;
        $processed = $checkpoint['processed'] ?? 0;

        if ($total > 0) {
            $percent = round(($processed / $total) * 100, 1);
        }

        return new JsonResponse([
            'checkpoint' => $checkpoint,
            'progress' => $percent,
            'errors' => $errors,
            'lastRecord' => $lastRecord,
            'job' => $job,
            'log' => mb_substr($log, -5000),
        ]);
    }

    #[Route('/process/stop', name: 'app_process_stop')]
    public function stop(): JsonResponse
    {
        $jobFile = $this->projectDir . '/var/state/job.json';
        if (!$this->fs->exists($jobFile)) {
            return new JsonResponse(['status' => 'no-job']);
        }

        $job = json_decode(file_get_contents($jobFile), true);
        $job['status'] = 'stopped';
        file_put_contents($jobFile, json_encode($job, JSON_PRETTY_PRINT));

        if (PHP_OS_FAMILY === 'Windows') {
            exec('taskkill /F /IM php.exe > NUL 2>&1');
        } else {
            exec('pkill -f "app:process-files"');
        }

        return new JsonResponse(['status' => 'stopped']);
    }

    private function readJson(string $path): ?array
    {
        $abs = str_starts_with($path, '/') ? $this->projectDir . $path : $path;
        if (!$this->fs->exists($abs)) return null;
        return json_decode(file_get_contents($abs), true);
    }

    private function readFile(string $path): string
    {
        $abs = str_starts_with($path, '/') ? $this->projectDir . $path : $path;
        if (!$this->fs->exists($abs)) return '(nessun log)';
        return file_get_contents($abs);
    }

    private function exportRelevantEnv(): array
    {
        return array_filter($_ENV, fn($k) =>
            str_starts_with($k, 'CSV_') ||
            str_starts_with($k, 'OUTPUT_') ||
            str_starts_with($k, 'SOURCE_') ||
            str_starts_with($k, 'LOG_') ||
            str_starts_with($k, 'CHECKPOINT_'),
            ARRAY_FILTER_USE_KEY
        );
    }
}
