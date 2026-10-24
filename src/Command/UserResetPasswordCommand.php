<?php

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\PasswordResetManager;
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
        private readonly PasswordResetManager $passwordResetManager,
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
                if (!is_string($value) || strlen($value) < 12 || !preg_match('/[A-Z]/', $value) || !preg_match('/[a-z]/', $value) || !preg_match('/\d/', $value)) {
                    throw new \RuntimeException('Le mot de passe doit respecter la politique de 12 caractères, majuscule, minuscule et chiffre.');
                }
                return $value;
            });
            $password = $io->askQuestion($question);
        }

        if (strlen((string) $password) < 12 || !preg_match('/[A-Z]/', (string) $password) || !preg_match('/[a-z]/', (string) $password) || !preg_match('/\d/', (string) $password)) {
            $io->error('Le mot de passe doit respecter la politique de 12 caractères, majuscule, minuscule et chiffre.');
            return Command::FAILURE;
        }

        $user->setPassword($this->passwordHasher->hashPassword($user, (string) $password));
        $this->passwordResetManager->revoke($user);

        $io->success(sprintf('Le mot de passe de %s (%s) a été mis à jour avec succès.', $user->getNomComplet(), $user->getEmail()));

        return Command::SUCCESS;
    }
}
