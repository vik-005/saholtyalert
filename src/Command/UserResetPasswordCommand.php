<?php

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:user:reset-password',
    description: 'Réinitialise le mot de passe d’un utilisateur (Agent, Manager ou Superadmin) en ligne de commande (Procédure de secours / Break-glass).',
)]
class UserResetPasswordCommand extends Command
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::OPTIONAL, 'Adresse email de l’utilisateur')
            ->addArgument('password', InputArgument::OPTIONAL, 'Nouveau mot de passe (optionnel, demandé interactivement si omis)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Plateforme GEI — Réinitialisation de mot de passe d’urgence');

        $email = $input->getArgument('email');
        if (!$email) {
            $email = $io->ask('Adresse email du compte à réinitialiser');
        }

        $email = strtolower(trim((string) $email));
        $user = $this->userRepository->findOneBy(['email' => $email]);

        if (!$user instanceof User) {
            $io->error(sprintf('Aucun utilisateur trouvé avec l’adresse email "%s".', $email));
            return Command::FAILURE;
        }

        $io->text([
            sprintf('Utilisateur identifié : <info>%s</info> (%s)', $user->getNomComplet(), $user->getEmail()),
            sprintf('Rôle actuel          : <comment>%s</comment>', $user->getRole()->label()),
        ]);

        $password = $input->getArgument('password');
        if (!$password) {
            $question = new Question('Nouveau mot de passe (12 caractères min, majuscule, minuscule, chiffre)');
            $question->setHidden(true);
            $question->setHiddenFallback(false);
            $question->setValidator(function ($value) {
                if (empty($value) || strlen($value) < 8) {
                    throw new \RuntimeException('Le mot de passe doit comporter au moins 8 caractères.');
                }
                return $value;
            });
            $password = $io->askQuestion($question);
        }

        $hashedPassword = $this->passwordHasher->hashPassword($user, (string) $password);
        $user->setPassword($hashedPassword);
        $this->em->flush();

        $io->success(sprintf('Le mot de passe de %s (%s) a été mis à jour avec succès.', $user->getNomComplet(), $user->getEmail()));

        return Command::SUCCESS;
    }
}
