<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\AuthSource;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class AuthSourceFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        // Corporate Active Directory
        $auth1 = new AuthSource();
        $auth1->setType('AuthSourceLdap');
        $auth1->setName('Corporate Active Directory');
        $auth1->setHost('ldap.company.com');
        $auth1->setPort(636);
        $auth1->setAccount('cn=redmine,ou=Service Accounts,dc=company,dc=com');
        $auth1->setAccountPassword('[ENCRYPTED_PASSWORD]');
        $auth1->setBaseDn('ou=Users,dc=company,dc=com');
        $auth1->setAttrLogin('sAMAccountName');
        $auth1->setAttrFirstname('givenName');
        $auth1->setAttrLastname('sn');
        $auth1->setAttrMail('mail');
        $auth1->setOntheflyRegister(true);
        $auth1->setTls(true);
        $auth1->setFilter('(&(objectClass=user)(!(userAccountControl:1.2.840.113556.1.4.803:=2)))');
        $auth1->setTimeout(10);
        $auth1->setVerifyPeer(true);
        
        $manager->persist($auth1);
        $this->addReference('auth-source-ad', $auth1);

        // OpenLDAP Server
        $auth2 = new AuthSource();
        $auth2->setType('AuthSourceLdap');
        $auth2->setName('OpenLDAP Server');
        $auth2->setHost('openldap.company.com');
        $auth2->setPort(389);
        $auth2->setAccount('cn=admin,dc=company,dc=com');
        $auth2->setAccountPassword('[ENCRYPTED_PASSWORD]');
        $auth2->setBaseDn('ou=people,dc=company,dc=com');
        $auth2->setAttrLogin('uid');
        $auth2->setAttrFirstname('givenName');
        $auth2->setAttrLastname('sn');
        $auth2->setAttrMail('mail');
        $auth2->setOntheflyRegister(true);
        $auth2->setTls(false);
        $auth2->setFilter('(objectClass=person)');
        $auth2->setTimeout(5);
        $auth2->setVerifyPeer(false);
        
        $manager->persist($auth2);
        $this->addReference('auth-source-openldap', $auth2);

        // Google Workspace LDAP
        $auth3 = new AuthSource();
        $auth3->setType('AuthSourceLdap');
        $auth3->setName('Google Workspace LDAP');
        $auth3->setHost('ldap.google.com');
        $auth3->setPort(636);
        $auth3->setAccount('cn=redmine-service@company.com,ou=Users,dc=company,dc=com');
        $auth3->setAccountPassword('[ENCRYPTED_PASSWORD]');
        $auth3->setBaseDn('dc=company,dc=com');
        $auth3->setAttrLogin('uid');
        $auth3->setAttrFirstname('givenName');
        $auth3->setAttrLastname('sn');
        $auth3->setAttrMail('mail');
        $auth3->setOntheflyRegister(false); // Manual user creation required
        $auth3->setTls(true);
        $auth3->setFilter('(objectClass=person)');
        $auth3->setTimeout(15);
        $auth3->setVerifyPeer(true);
        
        $manager->persist($auth3);
        $this->addReference('auth-source-google', $auth3);

        // Development LDAP (for testing)
        $auth4 = new AuthSource();
        $auth4->setType('AuthSourceLdap');
        $auth4->setName('Development LDAP');
        $auth4->setHost('dev-ldap.company.local');
        $auth4->setPort(389);
        $auth4->setAccount('cn=admin,dc=dev,dc=local');
        $auth4->setAccountPassword('devpassword123');
        $auth4->setBaseDn('ou=developers,dc=dev,dc=local');
        $auth4->setAttrLogin('cn');
        $auth4->setAttrFirstname('givenName');
        $auth4->setAttrLastname('sn');
        $auth4->setAttrMail('mail');
        $auth4->setOntheflyRegister(true);
        $auth4->setTls(false);
        $auth4->setFilter('(objectClass=inetOrgPerson)');
        $auth4->setTimeout(30);
        $auth4->setVerifyPeer(false);
        
        $manager->persist($auth4);
        $this->addReference('auth-source-dev', $auth4);

        // External Partner LDAP
        $auth5 = new AuthSource();
        $auth5->setType('AuthSourceLdap');
        $auth5->setName('Partner LDAP');
        $auth5->setHost('ldap.partner.com');
        $auth5->setPort(636);
        $auth5->setAccount('cn=redmine-svc,ou=External,dc=partner,dc=com');
        $auth5->setAccountPassword('[ENCRYPTED_PASSWORD]');
        $auth5->setBaseDn('ou=External Users,dc=partner,dc=com');
        $auth5->setAttrLogin('userPrincipalName');
        $auth5->setAttrFirstname('givenName');
        $auth5->setAttrLastname('sn');
        $auth5->setAttrMail('mail');
        $auth5->setOntheflyRegister(false); // Manual approval required for external users
        $auth5->setTls(true);
        $auth5->setFilter('(&(objectClass=user)(department=External))');
        $auth5->setTimeout(20);
        $auth5->setVerifyPeer(true);
        
        $manager->persist($auth5);
        $this->addReference('auth-source-partner', $auth5);

        $manager->flush();
    }
}