<?php
namespace App\Command;

use App\Service\SystemCheckService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class CheckEnvironmentCommand extends Command
{
    // 1️⃣  imposta sempre un nome esplicito
    protected static $defaultName = 'app:check-environment';
    protected static $defaultDescription = 'Esegue il controllo completo dell’ambiente e delle dipendenze';

    private SystemCheckService $checkService;

    public function __construct(SystemCheckService $checkService)
    {
        parent::__construct();            // 2️⃣  chiama SEMPRE il costruttore padre
        $this->checkService = $checkService;
    }

    // 3️⃣  per compatibilità aggiungi comunque un setName()
    protected function configure(): void
    {
        $this->setName(self::$defaultName);
        $this->setDescription(self::$defaultDescription);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $ok = $this->checkService->runChecks($io);

        return $ok ? Command::SUCCESS : Command::FAILURE;
    }
}
