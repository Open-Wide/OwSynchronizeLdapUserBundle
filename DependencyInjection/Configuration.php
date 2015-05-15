<?php

namespace OpenWide\SynchronizeLdapUserBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface {

    /**
     * {@inheritdoc}
     */
    public function getConfigTreeBuilder() {
        $treeBuilder = new TreeBuilder();
        $rootNode = $treeBuilder->root('openwide_synchronize_ldap_user');

        $rootNode
                ->children()
                    ->booleanNode('enabled')->defaultFalse()->end()
                    ->booleanNode('synchronize')->defaultTrue()->end()
                    ->scalarNode('default_user')->defaultValue('')->end()
                    ->booleanNode('verbose')->defaultFalse()->end()
                    ->integerNode('parent_group_content_id')->min(0)->defaultValue(11)->end()
                    ->integerNode('parent_group_location_id')->min(0)->defaultValue(12)->end()
                    ->scalarNode('default_password')->defaultValue('dfSq56Qsd4Fsqf')->end()
                    ->scalarNode('event_class')->defaultValue('OpenWide\SynchronizeLdapUserBundle\EventListener\LdapSecuritySubscriber')->end()
                    ->scalarNode('helper_class')->defaultValue('OpenWide\SynchronizeLdapUserBundle\Helper\UserHelper')->end()
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
                            ->scalarNode('filter_all_user')->defaultValue('(&(objectclass=person))')->end()
                            ->scalarNode('filter_member_of')->defaultValue(' ')->end()
                            ->scalarNode('field_member_of')->defaultValue('memberof')->end()
                        ->end()
                    ->end()
                ->end();

        return $treeBuilder;
    }
}
