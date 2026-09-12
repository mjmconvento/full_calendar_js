<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\UserRepository;
use App\Security\EmailVerifier;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Prints the verification link for an account instead of emailing it.
 *
 * For the two situations where the email cannot do its job: local development
 * with MAILER_DSN=null://null, where nothing is ever delivered, and an operator
 * helping someone whose address bounces. The link is exactly what the email
 * would have carried, expiry included.
 *
 * It is signed for the origin in DEFAULT_URI, and the signature covers the
 * host, so it only works when opened at that origin. Open it in a private
 * window: following it signs the browser in as that account.
 */
#[AsCommand(
    name: 'app:verification-link',
    description: 'Print the email verification link for an account, for when no email can reach it.',
)]
final class VerificationLinkCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EmailVerifier $verifier,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'The address the account was registered with');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = $input->getArgument('email');
        $user = is_string($email) ? $this->users->findOneByEmail($email) : null;

        if ($user === null) {
            $io->error(sprintf('No account is registered as "%s".', is_string($email) ? $email : ''));

            return Command::FAILURE;
        }

        if ($user->isVerified()) {
            $io->note(sprintf('%s is already verified; there is nothing to follow.', $user->getEmail()));

            return Command::SUCCESS;
        }

        // Bare, so the link can be piped or copied without picking it out of
        // a sentence.
        $output->writeln($this->verifier->verificationUrl($user));

        return Command::SUCCESS;
    }
}
