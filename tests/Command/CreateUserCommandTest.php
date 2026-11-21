<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\Membership;
use App\Entity\User;
use App\Enum\MembershipRole;
use App\Tests\Functional\FunctionalTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class CreateUserCommandTest extends FunctionalTestCase
{
    public function testCreatesAnAdminAndResetsPasswords(): void
    {
        $tester = new CommandTester((new Application(static::$kernel ?? self::bootKernel()))->find('signageforge:user:create'));

        $tester->execute(['email' => 'Boss@Example.com', '--password' => 'first-password', '--admin' => true]);
        $tester->assertCommandIsSuccessful();

        $tester->execute(['email' => 'second@example.com', '--password' => 'second-password', '--admin' => true]);
        $tester->execute(['email' => 'boss@example.com', '--password' => 'new-password']);
        $tester->assertCommandIsSuccessful();

        $this->em()->clear();
        $boss = $this->em()->getRepository(User::class)->findOneBy(['email' => 'boss@example.com']);
        $second = $this->em()->getRepository(User::class)->findOneBy(['email' => 'second@example.com']);
        self::assertNotNull($boss);
        self::assertNotNull($second);
        self::assertSame(MembershipRole::Owner, $this->role($boss));
        self::assertSame(MembershipRole::Admin, $this->role($second));
        self::assertContains('ROLE_ADMIN', $second->getRoles());

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertTrue($hasher->isPasswordValid($boss, 'new-password'));
    }

    private function role(User $user): MembershipRole
    {
        $membership = $user->getMemberships()->first();
        self::assertInstanceOf(Membership::class, $membership);

        return $membership->getRole();
    }

    public function testRejectsShortPasswordsAndBadEmails(): void
    {
        $tester = new CommandTester((new Application(static::$kernel ?? self::bootKernel()))->find('signageforge:user:create'));

        self::assertSame(2, $tester->execute(['email' => 'nope', '--password' => 'long-enough']));
        self::assertSame(2, $tester->execute(['email' => 'a@example.com', '--password' => 'short']));
    }
}
