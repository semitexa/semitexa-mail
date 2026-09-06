<?php

declare(strict_types=1);

namespace Semitexa\Mail\Application\Console\Command;

use Psr\Container\ContainerInterface;
use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Mail\Application\Service\AttachmentResolver;
use Semitexa\Mail\Application\Service\MailWorker;
use Semitexa\Mail\Domain\Contract\MailAttemptRepositoryInterface;
use Semitexa\Mail\Domain\Contract\MailerConfigResolverInterface;
use Semitexa\Mail\Domain\Contract\MailRepositoryInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'mail:work', description: 'Run the dedicated mail delivery worker')]
final class MailWorkCommand extends Command
{
    /**
     * Injected rather than reached for statically. The services below are still
     * resolved lazily inside the command body, and deliberately so: every
     * #[AsCommand] class is instantiated and injected at console boot, so
     * injecting a repository directly would open its connection on every
     * `bin/semitexa` invocation — and a dependency that failed to build would
     * make this command vanish from the list instead of reporting the failure.
     */
    #[InjectAsReadonly]
    protected ContainerInterface $container;

    protected function configure(): void
    {
        $this
            ->setName('mail:work')
            ->setDescription('Run the dedicated mail delivery worker')
            ->addArgument(
                name:        'transport',
                mode:        InputArgument::OPTIONAL,
                description: 'Queue transport: nats or in-memory (default from EVENTS_ASYNC)',
                default:     null,
            )
            ->addArgument(
                name:        'queue',
                mode:        InputArgument::OPTIONAL,
                description: 'Queue name (default: mail)',
                default:     null,
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io        = new SymfonyStyle($input, $output);
        $transport = $input->getArgument('transport');
        $queue     = $input->getArgument('queue');

        $io->title('Mail worker');

        try {
            $container      = $this->container;
            $mailRepository = $container->get(MailRepositoryInterface::class);
            $attemptRepo    = $container->get(MailAttemptRepositoryInterface::class);
            $configResolver = $container->get(MailerConfigResolverInterface::class);
            $attachResolver = $container->get(AttachmentResolver::class);

            $worker = new MailWorker($mailRepository, $attemptRepo, $configResolver, $attachResolver);
            $worker->setOutput($output);
            $worker->run($transport, $queue);
        } catch (\Throwable $e) {
            $io->error('Mail worker failed: ' . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
