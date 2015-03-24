<?php

namespace Ow\SynchronizeLdapUserBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface {

    /**
     * {@inheritdoc}
     */
    public function getConfigTreeBuilder() {
        $treeBuilder = new TreeBuilder();
        $rootNode = $treeBuilder->root('ow_synchronize_ldap_user');

        $rootNode
                ->children()
                    ->booleanNode('enabled')->defaultFalse()->end()
                    ->booleanNode('synchronize')->defaultTrue()->end()
                    ->booleanNode('verbose')->defaultFalse()->end()
                    ->integerNode('parent_group_content_id')->min(0)->defaultValue(11)->end()
                    ->integerNode('parent_group_location_id')->min(0)->defaultValue(12)->end()
                    ->scalarNode('default_password')->defaultValue('dfSq56Qsd4Fsqf')->end()
                    ->scalarNode('event_class')->defaultValue('Ow\SynchronizeLdapUserBundle\EventListener\LdapSecuritySubscriber')->end()
                    ->scalarNode('helper_class')->defaultValue('Ow\SynchronizeLdapUserBundle\Helper\UserHelper')->end()
                    ->scalarNode('mode')->defaultValue('update')->end()
                    ->arrayNode('fields')
                        ->children()
                            ->arrayNode('user')
                                ->isRequired()
                                ->requiresAtLeastOneElement()
                                ->useAttributeAsKey('name')
                                ->prototype('array')
                                    ->children()
                                        ->scalarNode('value')->isRequired()->end()
                                    ->end()
                                ->end()
                            ->end()
                            ->arrayNode('group')
                                ->isRequired()
                                ->requiresAtLeastOneElement()
                                ->useAttributeAsKey('name')
                                ->prototype('array')
                                    ->children()
                                        ->scalarNode('value')->isRequired()->end()
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                    ->arrayNode('ldap')
                        ->children()
                            ->scalarNode('base_dn')->defaultValue('dc=example,dc=com')->end()
                            ->scalarNode('filter_user')->defaultValue('(&(objectclass=person)(uid=**USERNAME**))')->end()
                            ->scalarNode('filter_group')->defaultValue('(&(objectclass=groupOfUniqueNames)(uniquemember=uid=**USERNAME**,dc=example,dc=com))')->end()
                        ->end()
                    ->end()
                ->end();

        return $treeBuilder;
    }
}
