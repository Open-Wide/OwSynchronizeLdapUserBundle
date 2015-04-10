<?php

namespace Ow\SynchronizeLdapUserBundle\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;
use Symfony\Component\DependencyInjection\Loader;

class OwSynchronizeLdapUserExtension extends Extension
{
    /**
     * {@inheritdoc}
     */
    public function load(array $configs, ContainerBuilder $container) {
        
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);
        $loader = new Loader\YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));

        if (isset($config['enabled']) && $config['enabled']) {
            $loader->load('services.yml');
            $container->setParameter('ow_synchronize_ldap_user.ldap.parent_group.content_id', $config['parent_group_content_id']);
            $container->setParameter('ow_synchronize_ldap_user.ldap.parent_group.location_id', $config['parent_group_location_id']);
            $container->setParameter('ow_synchronize_ldap_user.ldap.user_password', $config['default_password']);
            $container->setParameter('ow_synchronize_ldap_user.post_event_subscriber_ldap.class', $config['event_class']);
            $container->setParameter('ow_synchronize_ldap_user.user_helper.class', $config['helper_class']);
            $container->setParameter('ow_synchronize_ldap_user.mode', $config['mode']);
            $container->setParameter('ow_synchronize_ldap_user.synchronize', $config['synchronize']);
            $container->setParameter('ow_synchronize_ldap_user.default_user', $config['default_user']);
            $container->setParameter('ow_synchronize_ldap_user.base_dn', $config['ldap']['base_dn']);
            $container->setParameter('ow_synchronize_ldap_user.filter_user', $config['ldap']['filter_user']);
            $container->setParameter('ow_synchronize_ldap_user.filter_all_user', $config['ldap']['filter_all_user']);
            $container->setParameter('ow_synchronize_ldap_user.filter_group', $config['ldap']['filter_group']);
            
            $userFieldsLdap = $userFieldsEz = array();
            foreach( $config['fields']['user'] as $key => $field ){
                $userFieldsEz[] = $key;
                $userFieldsLdap[] = $field['value'];
            }
            
            $groupFieldsLdap = $groupFieldsEz = array();
            foreach( $config['fields']['group'] as $key => $field ){
                $groupFieldsEz[] = $key;
                $groupFieldsLdap[] = $field['value'];
            }
            
            $container->setParameter('ow_synchronize_ldap_user.ldap.fields_user_ldap', $userFieldsLdap);
            $container->setParameter('ow_synchronize_ldap_user.ldap.fields_user_ez', $userFieldsEz);
            $container->setParameter('ow_synchronize_ldap_user.ldap.fields_group_ldap', $groupFieldsLdap);
            $container->setParameter('ow_synchronize_ldap_user.ldap.fields_group_ez', $groupFieldsEz);
        }
    }
}
