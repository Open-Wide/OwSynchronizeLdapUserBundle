<?php

namespace Ow\SynchronizeLdapUserBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class UpdateCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('synchronize:update')
            ->setDescription('Updating the rights of a ldap user on EZ user')
            ->addArgument('username', InputArgument::REQUIRED, 'Login ldap of user')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $username = $input->getArgument('username');
        $output->writeln("Update rights for ".$username);
        $userHelper = $this->getContainer()->get('ow_synchronize_ldap_user.user_helper')->synchronizeUserAndGroup($username) ;
        $output->writeln("End");
    }
}
