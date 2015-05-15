<?php

namespace OpenWide\SynchronizeLdapUserBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class InitCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('synchronize:init')
            ->setDescription('Updating the rights of all ldap user on EZ user')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln("Init rights for all user");
        
        $users = $this->getContainer()->get('ow_synchronize_ldap_user.user_helper')->getAllUserLdap() ;

        $i = 1;
        foreach($users as $username){
            if(isset($username['uid'][0])){
                $output->writeln($i ." ".$username['uid'][0]);
                $userHelper = $this->getContainer()->get('ow_synchronize_ldap_user.user_helper')->synchronizeUserAndGroup($username['uid'][0]) ;
                $i++;
            }
        }
        $output->writeln("End");
    }
}