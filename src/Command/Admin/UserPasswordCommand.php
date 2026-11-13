<?php

declare(strict_types=1);

namespace App\Command\Admin;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(name: 'symine:user:password', description: 'Change the password of a user')]
final class UserPasswordCommand
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Login')] string $login,
        #[Argument('New password (asked when omitted)')] ?string $password = null,
        #[Option('Force password change at next login')] bool $mustChangePassword = false,
    ): int {
        $user = $this->em->getRepository(User::class)->findOneBy(['login' => $login]);
        if (!$user instanceof User || '' === $login) {
            $io->error('User not found.');

            return Command::FAILURE;
        }
        $password ??= (string) $io->askHidden('New password');
        if ('' === $password) {
            $io->error('Password cannot be blank.');

            return Command::FAILURE;
        }
        $user->hashedPassword = $this->hasher->hashPassword($user, $password);
        $user->salt = null;
        $user->passwdChangedOn = new \DateTimeImmutable();
        $user->mustChangePasswd = $mustChangePassword;
        $this->em->flush();
        $io->success(\sprintf('Password of "%s" changed.', $login));

        return Command::SUCCESS;
    }
}
