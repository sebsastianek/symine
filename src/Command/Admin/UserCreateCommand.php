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

#[AsCommand(name: 'symine:user:create', description: 'Create a user account')]
final class UserCreateCommand
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Login')] string $login,
        #[Argument('Email address')] string $mail,
        #[Option('Password (generated when omitted)')] ?string $password = null,
        #[Option('First name')] string $firstname = '',
        #[Option('Last name')] string $lastname = '',
        #[Option('Give administrator rights')] bool $admin = false,
        #[Option('Force password change at next login')] bool $mustChangePassword = false,
        #[Option('Language')] string $language = '',
    ): int {
        if (!preg_match('/\A[a-z0-9_\-@.]*\z/i', $login) || '' === $login || mb_strlen($login) > 255) {
            $io->error('Invalid login.');

            return Command::FAILURE;
        }
        if (null !== $this->em->getRepository(User::class)->findOneBy(['login' => $login])) {
            $io->error('Login has already been taken.');

            return Command::FAILURE;
        }
        if (!filter_var($mail, \FILTER_VALIDATE_EMAIL)) {
            $io->error('Invalid email address.');

            return Command::FAILURE;
        }
        $exists = (int) $this->em->createQuery('SELECT COUNT(e.id) FROM App\Entity\EmailAddress e WHERE LOWER(e.address) = :m')
            ->setParameter('m', mb_strtolower($mail))->getSingleScalarResult();
        if ($exists > 0) {
            $io->error('Email has already been taken.');

            return Command::FAILURE;
        }
        $generated = null === $password;
        $password ??= rtrim(strtr(base64_encode(random_bytes(12)), '+/', 'Ab'), '=');
        $user = new User();
        $user->login = $login;
        $user->firstname = '' !== $firstname ? $firstname : $login;
        $user->lastname = '' !== $lastname ? $lastname : $login;
        $user->admin = $admin;
        $user->status = User::STATUS_ACTIVE;
        $user->language = $language;
        $user->mustChangePasswd = $mustChangePassword;
        $user->setMail($mail);
        $user->hashedPassword = $this->hasher->hashPassword($user, $password);
        $user->salt = null;
        $user->passwdChangedOn = new \DateTimeImmutable();
        $this->em->persist($user);
        $this->em->flush();
        $io->success(\sprintf('User "%s" created (id %d).', $login, $user->id).($generated ? ' Generated password: '.$password : ''));

        return Command::SUCCESS;
    }
}
