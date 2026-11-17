<?php

namespace Drupal\eic_groups;

use CommerceGuys\Addressing\AddressFormat\AddressField;
use Drupal\address\FieldHelper;
use Drupal\comment\CommentInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Locale\CountryManager;
use Drupal\Core\Path\CurrentPathStack;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\eic_content\Constants\DefaultContentModerationStates;
use Drupal\eic_events\Constants\Event;
use Drupal\eic_groups\Constants\GroupJoiningMethodType;
use Drupal\eic_groups\Constants\GroupVisibilityType;
use Drupal\eic_organisations\Constants\Organisations;
use Drupal\eic_overviews\GroupOverviewPages;
use Drupal\eic_user\UserHelper;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupRelationship;
use Drupal\group\Entity\GroupRelationshipInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupTypeInterface;
use Drupal\group\GroupMembership;
use Drupal\group\Plugin\Group\Relation\GroupRelationTypeManagerInterface;
use Drupal\message\MessageInterface;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;

/**
 * EICGroupsHelper service that provides helper functions for groups.
 */
class EICGroupsHelper implements EICGroupsHelperInterface {

  use StringTranslationTrait;

  const GROUP_OWNER_ROLE = 'group-owner';

  const GROUP_ADMINISTRATOR_ROLE = 'group-admin';

  const GROUP_MEMBER_ROLE = 'group-member';

  const GROUP_TYPE_OWNER_ROLE = 'owner';

  const GROUP_TYPE_ADMINISTRATOR_ROLE = 'admin';

  const GROUP_TYPE_MEMBER_ROLE = 'member';

  const INVITEE_INVITATION_EMAIL_LIMIT = 2;

  /**
   * The database connection service.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The current route match service.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * The module handler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The current user service.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected $time;

  /**
   * The current path service.
   *
   * @var \Drupal\Core\Path\CurrentPathStack
   */
  protected $currentPath;

  /**
   * The group content enabler manager service.
   *
   * @var \Drupal\group\Plugin\Group\Relation\GroupRelationTypeManagerInterface
   */
  protected $groupContentPluginManager;

  /**
   * Constructs a new EventsHelperService object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection service.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The current route match service.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler service.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user service.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\Core\Path\CurrentPathStack $current_path
   *   The current path service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param \Drupal\group\Plugin\Group\Relation\GroupRelationTypeManagerInterface $group_content_enabler_manager
   *   The group content enabler manager service.
   */
  public function __construct(
    Connection $database,
    RouteMatchInterface $route_match,
    ModuleHandlerInterface $module_handler,
    AccountProxyInterface $current_user,
    TimeInterface $time,
    CurrentPathStack $current_path,
    EntityTypeManagerInterface $entity_type_manager,
    GroupRelationTypeManagerInterface $group_content_enabler_manager
  ) {
    $this->database = $database;
    $this->routeMatch = $route_match;
    $this->moduleHandler = $module_handler;
    $this->currentUser = $current_user;
    $this->time = $time;
    $this->currentPath = $current_path;
    $this->entityTypeManager = $entity_type_manager;
    $this->groupContentPluginManager = $group_content_enabler_manager;
  }

  /**
   * Check if a group can be flagged depending on the moderation state.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The group entity.
   *
   * @return bool
   *   TRUE if the group is not in Pending or Draft state.
   */
  public static function groupIsFlaggable(GroupInterface $group) {
    $moderation_state = $group->get('moderation_state')->value;
    return !in_array(
      $moderation_state,
      [
        GroupsModerationHelper::GROUP_PENDING_STATE,
        GroupsModerationHelper::GROUP_DRAFT_STATE,
      ]
    );
  }

  /**
   * Checks if a user is a group admin of a given group.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The group entity.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user account object.
   * @param \Drupal\group\GroupMembership|null $membership
   *   The group membership (optional).
   *
   * @return bool
   *   TRUE if user is a group admin.
   */
  public static function userIsGroupAdmin(
    GroupInterface $group,
    AccountInterface $account,
    GroupMembership $membership = NULL
  ) {
    // If user is power user, return TRUE.
    if (UserHelper::isPowerUser($account, $group)) {
      return TRUE;
    }

    $membership = $membership ?: $group->getMember($account);

    // User is not a member of the group. We return FALSE.
    if (!$membership) {
      return FALSE;
    }

    $membership_roles = $membership->getRoles();
    $is_admin = FALSE;

    foreach ($membership_roles as $role) {
      $is_admin = in_array(
        $role->id(),
        [
          self::getGroupTypeRole($group->bundle(), self::GROUP_TYPE_ADMINISTRATOR_ROLE),
          self::getGroupTypeRole($group->bundle(), self::GROUP_TYPE_OWNER_ROLE),
        ]
      );

      if (!$is_admin) {
        continue;
      }

      break;
    }

    return $is_admin;
  }

  /**
   * Get the current group owner of a group.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The group entity.
   * @param bool $return_as_membership_object
   *   (optional) Defaults to FALSE.
   *
   * @return \Drupal\user\UserInterface|\Drupal\group\GroupMembership|null
   *   Returns a user entity if $return_as_membership_object is FALSE (this is
   *   the default) and otherwise an GroupMembership object.
   */
  public static function getGroupOwner(GroupInterface $group, $return_as_membership_object = FALSE) {
    $owners = $group->getMembers($group->bundle() . '-' . self::GROUP_TYPE_OWNER_ROLE);

    if (empty($owners)) {
      return NULL;
    }

    /** @var \Drupal\group\GroupMembership $owner_membership */
    $owner_membership = reset($owners);

    if ($return_as_membership_object) {
      return $owner_membership;
    }

    return $owner_membership->getUser();
  }

  /**
   * Provides the list of roles for the given group types.
   *
   * @param string[]|null $group_types
   *   An array of group types to filter on. Default to all.
   * @param bool $include_internal_roles
   *   Whether to include group internal roles. Default to FALSE.
   *
   * @return array
   *   An array keyed as follows:
   *   - group_type: the group type machine name
   *     - label: the group type's label.
   *     - roles: array of roles.
   *       - role_id: the role machine name.
   *         => label of the role.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function getGroupRoles($group_types = NULL, $include_internal_roles = FALSE) {
    $roles = [];

    /** @var \Drupal\group\Entity\GroupTypeInterface[] $group_types */
    $group_types = $this->entityTypeManager->getStorage('group_type')->loadMultiple($group_types);
    foreach ($group_types as $group_type) {
      $roles[$group_type->id()]['label'] = $group_type->label();
      $group_roles = $group_type->getRoles($include_internal_roles);
      foreach ($group_roles as $group_role) {
        $roles[$group_type->id()]['roles'][$group_role->id()] = $group_role->label();
      }
    }

    return $roles;
  }

  /**
   * Returns the correct role machine name for the given group type and role.
   *
   * @param string $group_type
   *   The group type ID.
   * @param string $role
   *   The role to check. Can be either "admin", "owner" or "member".
   *
   * @return string|null
   *   The group role machine name or NULL if not found.
   */
  public static function getGroupTypeRole(string $group_type, string $role) {
    $roles = [
      'group' => [
        self::GROUP_TYPE_ADMINISTRATOR_ROLE => self::GROUP_ADMINISTRATOR_ROLE,
        self::GROUP_TYPE_OWNER_ROLE => self::GROUP_OWNER_ROLE,
        self::GROUP_TYPE_MEMBER_ROLE => self::GROUP_MEMBER_ROLE,
      ],
      'event' => [
        self::GROUP_TYPE_ADMINISTRATOR_ROLE => Event::GROUP_ADMINISTRATOR_ROLE,
        self::GROUP_TYPE_OWNER_ROLE => Event::GROUP_OWNER_ROLE,
        self::GROUP_TYPE_MEMBER_ROLE => Event::GROUP_MEMBER_ROLE,
      ],
      'organisation' => [
        self::GROUP_TYPE_ADMINISTRATOR_ROLE => Organisations::GROUP_ADMINISTRATOR_ROLE,
        self::GROUP_TYPE_OWNER_ROLE => Organisations::GROUP_OWNER_ROLE,
        self::GROUP_TYPE_MEMBER_ROLE => Organisations::GROUP_MEMBER_ROLE,
      ],
    ];

    return $roles[$group_type][$role] ?? NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getGroupFromRoute() {
    $entity = FALSE;
    $parameters = $this->routeMatch->getParameters()->all();
    if (!empty($parameters['group']) && is_numeric($parameters['group'])) {
      $group = Group::load($parameters['group']);
      return $group;
    }
    if (!empty($parameters)) {
      foreach ($parameters as $parameter) {
        if ($parameter instanceof EntityInterface) {
          $entity = $parameter;
          break;
        }
      }
    }
    if ($entity) {
      return $this->getOwnerGroupByEntity($entity);
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function getOwnerGroupByEntity(EntityInterface $entity) {
    $group = FALSE;
    if ($entity instanceof GroupInterface) {
      return $entity;
    }

    if ($entity instanceof MessageInterface) {
      $group_ref_id = NULL;
      if ($entity->hasField('field_group_ref') && $entity->get('field_group_ref')->entity instanceof GroupInterface) {
        $group_ref_id = $entity->get('field_group_ref')->entity->id();
      }
      $group = $group_ref_id ? Group::load($group_ref_id) : NULL;

      return $group;
    }

    if ($entity instanceof GroupRelationshipInterface) {
      return $entity->getGroup();
    }

    if ($entity instanceof NodeInterface) {
      // Cannot load group content for an unsaved entity.
      if ($entity->isNew()) {
        return FALSE;
      }
      // Load all the group content for this entity.
      $group_contents = GroupRelationship::loadByEntity($entity);

      // We look for the first group_node group_content entity.
      foreach ($group_contents as $group_content_item) {
        if (strpos($group_content_item->getRelationshipType()->getPluginId(), 'group_node:') === 0) {
          $group = $group_content_item->getGroup();
          break;
        }
      }

      if (!empty($group_content)) {
        $group = $group_content->getGroup();
      }
    }
    return $group;
  }

  /**
   * {@inheritdoc}
   */
  public function getGroupContentOperationLinks(
    GroupInterface $group,
    $limit_entities = [],
    CacheableMetadata $cacheable_metadata = NULL
  ) {
    $operation_links = [];
    foreach ($group->getGroupType()->getInstalledPlugins() as $plugin) {
      /** @var \Drupal\group\Plugin\Group\Relation\GroupRelationInterface $plugin */
      if (!empty($limit_entities) && !in_array($plugin->getEntityTypeId(), $limit_entities)) {
        continue;
      }

      $plugin_operation_links = $plugin->getGroupOperations($group);

      // Remove operation plugins if the user doesn't have access.
      foreach ($plugin_operation_links as $key => $plugin_operation_link) {
        if ($plugin_operation_link['url']->access()) {
          continue;
        }

        unset($plugin_operation_link[$key]);
      }

      $operation_links += $plugin_operation_links;

      // Retrieve the operations from the installed content plugins and merges
      // cacheable metadata.
      if (!is_null($cacheable_metadata)) {
        // $cacheable_metadata = $cacheable_metadata->merge($plugin->getGroupOperationsCacheableMetadata());
      }
    }

    if ($operation_links) {
      // Allow modules to alter the collection of gathered links.
      $this->moduleHandler->alter('group_operations', $operation_links, $group);

      // Sort the operations by weight.
      uasort($operation_links, '\Drupal\Component\Utility\SortArray::sortByWeightElement');
    }

    return $operation_links;
  }

  /**
   * {@inheritdoc}
   */
  public function getGroupBookPage(GroupInterface $group) {
    if (!$group->getGroupType()->hasPlugin('group_node:book')) {
      return NULL;
    }

    $group_content_type_id = $this->entityTypeManager
      ->getStorage('group_content_type')
      ->getRelationshipTypeId($group->bundle(), 'group_node:book');

    $query = $this->database->select('group_relationship_field_data', 'gp');
    $query->condition('gp.type', $group_content_type_id);
    $query->condition('gp.gid', $group->id());
    $query->join('book', 'b', 'gp.entity_id = b.nid');
    $query->fields('b', ['bid', 'nid']);
    $query->condition('b.pid', 0);
    $query->orderBy('b.weight');

    $results = $query->execute()->fetchAll(\PDO::FETCH_OBJ);
    if (!empty($results)) {
      return $results[0]->nid;
    }

    return NULL;
  }

  /**
   * Returns a custom description for the given group_flex plugin.
   *
   * @param string $plugin_type
   *   The plugin type can be one of the following type:
   *   - visibility: the GroupVisibility plugin type.
   *   - joining_method: the GroupJoiningMethod plugin type.
   * @param string $plugin_id
   *   The plugin ID.
   * @param string $group_type
   *   (optional) The group type.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup|string
   *   The description for the given plugin.
   */
  public function getGroupFlexPluginDescription(string $plugin_type, string $plugin_id, string $group_type = 'group') {
    $key = "$plugin_type-$plugin_id";

    switch ($key) {
      case 'visibility-' . GroupVisibilityType::GROUP_VISIBILITY_PUBLIC:
        return $this->t("This @group-type is visible to everyone visiting the @group-type. You're welcome to scroll through the @group-type's content. If you want to participate, please become a group member.",
          ['@group-type' => $group_type]);

      case 'visibility-' . GroupVisibilityType::GROUP_VISIBILITY_COMMUNITY:
        return $this->t("This @group-type is visible to every person that is a member of the EIC Community and has joined this platform. You're welcome to scroll through the @group-type's content. If you want to participate, please become a group member.",
          ['@group-type' => $group_type]);

      case 'visibility-' . GroupVisibilityType::GROUP_VISIBILITY_CUSTOM_RESTRICTED:
        return $this->t('This @group-type is visible to every person that has joined the EIC community that also complies with the following restrictions. You can see this @group-type because the organisation you work for is allowed to see this content or the @group-type owners and administrators have chosen to specifically grant you access to this @group-type. If you want to participate, please become a group member.',
          ['@group-type' => $group_type]);

      case 'visibility-' . GroupVisibilityType::GROUP_VISIBILITY_PRIVATE:
        return $this->t('A private @group-type is only visible to people who received an invitation via email and accepted it. No one else can see this @group-type.',
          ['@group-type' => $group_type]);

      case 'visibility-' . GroupVisibilityType::GROUP_VISIBILITY_SENSITIVE:
        return $this->t('A sensitive @group-type is only visible to people who received an invitation via email, accepted it and has the role to see sensitive content. No one else can see this @group-type.',
          ['@group-type' => $group_type]);

      case 'joining_method-' . GroupJoiningMethodType::GROUP_JOINING_METHOD_TU_OPEN:
        return $this->t('This means that EIC Community members can join this @group-type immediately by clicking "join group".',
          ['@group-type' => $group_type]);

      case 'joining_method-' . GroupJoiningMethodType::GROUP_JOINING_METHOD_TU_MEMBERSHIP_REQUEST:
        return $this->t('This means that EIC Community members can request to join this @group-type. This request needs to be validated by the @group-type owner or administrator.',
          ['@group-type' => $group_type]);

      default:
        return '';
    }
  }

  /**
   * Returns a list of groups based on the given visibility type.
   *
   * @param string $visibility
   *   The visibility type.
   *
   * @return array
   *   An array of Group entities.
   */
  public function getGroupsByVisibility(string $visibility) {
    // Group visibility came from oec_group_flex, which this project no longer
    // installs, and its oec_group_visibility table is gone. The table never
    // held a row in any environment -- it is empty here and has no INSERT
    // statements in the TEST dump -- so this already returned an empty array
    // every time it was called. Querying the dropped table would now throw.
    return [];
  }

  /**
   * Returns a custom title for the given group_flex plugin.
   *
   * @param string $plugin_type
   *   The plugin type can be one of the following type:
   *   - visibility: the GroupVisibility plugin type.
   *   - joining_method: the GroupJoiningMethod plugin type.
   * @param string $plugin_id
   *   The plugin ID.
   * @param string $format
   *   The format of the label. Can be 'default' or 'short'. Defaults to
   *   'default'.
   * @param string $group_type
   *   The group type. Defaults to 'group'.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup|string
   *   The description for the given plugin.
   */
  public function getGroupFlexPluginTitle(
    string $plugin_type,
    string $plugin_id,
    string $format = 'default',
    string $group_type = 'group'
  ) {
    $key = "$plugin_type-$plugin_id";

    $labels = [
      'visibility-' . GroupVisibilityType::GROUP_VISIBILITY_PUBLIC => [
        'default' => $this->t('Public @group-type', ['@group-type' => $group_type]),
        'short' => $this->t('Public'),
      ],
      'visibility-' . GroupVisibilityType::GROUP_VISIBILITY_COMMUNITY => [
        'default' => $this->t('Community members only'),
        'short' => $this->t('Community members'),
      ],
      'visibility-' . GroupVisibilityType::GROUP_VISIBILITY_CUSTOM_RESTRICTED => [
        'default' => $this->t('Restricted @group-type', ['@group-type' => $group_type]),
        'short' => $this->t('Restricted'),
      ],
      'visibility-' . GroupVisibilityType::GROUP_VISIBILITY_PRIVATE => [
        'default' => $this->t('Private @group-type', ['@group-type' => $group_type]),
        'short' => $this->t('Private'),
      ],
      'visibility-' . GroupVisibilityType::GROUP_VISIBILITY_SENSITIVE => [
        'default' => $this->t('Sensitive @group-type', ['@group-type' => $group_type]),
        'short' => $this->t('Sensitive'),
      ],
      'joining_method-' . GroupJoiningMethodType::GROUP_JOINING_METHOD_TU_OPEN => [
        'default' => $this->t('Open'),
        'short' => $this->t('Open'),
      ],
      'joining_method-' . GroupJoiningMethodType::GROUP_JOINING_METHOD_TU_MEMBERSHIP_REQUEST => [
        'default' => $this->t('Moderated'),
        'short' => $this->t('Moderated'),
      ],
    ];

    if (!empty($labels[$key][$format])) {
      return $labels[$key][$format];
    }

    return '';
  }

  /**
   * Returns the list of installed plugins for a group type.
   *
   * @param \Drupal\group\Entity\GroupTypeInterface|null $group_type
   *   The group type object. If none provided, will return for all group types.
   *
   * @return string[]
   *   An array of plugin IDs.
   */
  public function getGroupTypeEnabledPlugins(GroupTypeInterface $group_type = NULL) {
    return $this->groupContentPluginManager->getInstalledIds($group_type);
  }

  /**
   * Returns the list of enabled group_node plugins for the given group type.
   *
   * @param \Drupal\group\Entity\GroupTypeInterface $group_type
   *   The group type object.
   *
   * @return array
   *   An array of plugin type IDs.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function getGroupTypeEnabledContentPlugins(GroupTypeInterface $group_type) {
    $plugins = [];
    /** @var \Drupal\node\NodeTypeInterface $node_type */
    foreach ($this->entityTypeManager->getStorage('node_type')->loadMultiple() as $node_type) {
      if ($this->isGroupTypePluginEnabled($group_type, 'group_node', $node_type->id())) {
        // @todo Get real content type config id?
        // @see \Drupal\group\Entity\GroupRelationshipType::createFromPlugin().
        $plugins[] = $group_type->id() . '-group_node-' . $node_type->id();
      }
    }
    return $plugins;
  }

  /**
   * Checks if a plugin is enabled for the given group type.
   *
   * @param \Drupal\group\Entity\GroupTypeInterface $group_type
   *   The group type object.
   * @param string $plugin_type
   *   The plugin type.
   * @param string $bundle
   *   The bundle to append to the plugin type.
   *   E.g. 'group_node:news'.
   *
   * @return bool
   *   TRUE if plugin is enabled, FALSE otherwise.
   */
  public function isGroupTypePluginEnabled(
    GroupTypeInterface $group_type,
    string $plugin_type,
    string $bundle = ''
  ): bool {
    $enabled_plugins = $this->getGroupTypeEnabledPlugins($group_type);
    $plugin_id = $plugin_type;
    if (!empty($bundle)) {
      $plugin_id .= ":$bundle";
    }
    return in_array($plugin_id, $enabled_plugins);
  }

  /**
   * Returns the list of existing group types.
   *
   * @return \Drupal\group\Entity\GroupTypeInterface[]
   *   An array of group type objects.
   */
  public function getGroupTypes() {
    return $this->entityTypeManager->getStorage('group_type')->loadMultiple();
  }

  /**
   * Returns nodes that belong to a group.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The group object.
   *
   * @return \Drupal\node\NodeInterface[]
   *   An array of node entities.
   */
  public function getGroupNodes(GroupInterface $group) {
    $content = [];
    foreach ($this->getGroupTypeEnabledPlugins($group->getGroupType()) as $plugin_id) {
      if (strpos($plugin_id, 'group_node') !== 0) {
        continue;
      }
      /** @var \Drupal\group\Entity\GroupRelationshipInterface $group_content */
      foreach ($group->getRelationships($plugin_id) as $group_content) {
        $content[] = $group_content->getEntity();
      }
    }
    return $content;
  }

  /**
   * Determines if a group has content.
   *
   * Considered as content:
   *  - Discussions
   *  - Documents
   *  - Wiki pages.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The group for which the check is.
   */
  public function hasContent(GroupInterface $group) {
    $query = $this->database->select('group_relationship_field_data', 'gp');
    $relationship_type_storage = $this->entityTypeManager->getStorage('group_content_type');
    $query->condition('gp.type', [
      $relationship_type_storage->getRelationshipTypeId($group->bundle(), 'group_node:discussion'),
      $relationship_type_storage->getRelationshipTypeId($group->bundle(), 'group_node:document'),
      $relationship_type_storage->getRelationshipTypeId($group->bundle(), 'group_node:wiki_page'),
    ], 'IN');
    $query->condition('gp.gid', $group->id());
    $query->fields('gp', ['id']);

    return !empty($query->execute()->fetchAll(\PDO::FETCH_OBJ));
  }

  /**
   * Checks if the current page is a group page.
   *
   * @return \Drupal\group\Entity\Group|bool
   *   Returns the group entity object if the current page is a group page.
   */
  public function isGroupPage() {
    $is_group_page = FALSE;
    $current_path = $this->currentPath->getPath();
    $current_url = Url::fromUri("internal:" . $current_path);

    if (!$current_url->isRouted()) {
      return $is_group_page;
    }

    $route_name = $current_url->getRouteName();
    $route_parameters = $current_url->getRouteParameters();

    switch ($route_name) {
      case 'entity.group.canonical':
      case 'entity.group.edit_form':
      case 'eic_groups.about_page':
      case 'view.eic_group_members.page_group_members':
      case 'view.admin_blocked_entities.page_admin_group_blocked_history':
      case GroupOverviewPages::DISCUSSIONS:
      case GroupOverviewPages::FILES:
      case GroupOverviewPages::MEMBERS:
      case GroupOverviewPages::SEARCH:
      case GroupOverviewPages::LATEST_ACTIVITY:
      case GroupOverviewPages::EVENTS:
        if (is_numeric($route_parameters['group'])) {
          $group = Group::load($route_parameters['group']);
        }
        elseif ($route_parameters['group'] instanceof GroupInterface) {
          $group = $route_parameters['group'];
        }
        else {
          break;
        }
        $is_group_page = $group;
        break;

      case 'entity.node.canonical':
        if (empty($route_parameters['node'])) {
          break;
        }

        if (is_numeric($route_parameters['node'])) {
          $node = Node::load($route_parameters['node']);
        }

        if (!isset($node) && !$route_parameters['node'] instanceof NodeInterface) {
          break;
        }

        $group = $this->getOwnerGroupByEntity($node);

        if (!$group) {
          break;
        }

        $is_group_page = $group;
        break;
    }

    return $is_group_page;
  }

  /**
   * Returns a list of groups based on their topics.
   *
   * This function assumes that all groups have a field_vocab_topics.
   *
   * @param \Drupal\taxonomy\TermInterface $term
   *   The term to look for.
   * @param string[] $group_types
   *   An array of group type machine names. If empty, the function will return
   *   results for all types.
   * @param bool $published_only
   *   Whether to return published groups only.
   *
   * @return array|int
   *   And array of group IDs.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function getGroupsByTopic(TermInterface $term, array $group_types = [], bool $published_only = TRUE) {
    /** @var \Drupal\Core\Entity\Query\QueryInterface $query */
    $query = $this->entityTypeManager->getStorage('group')->getQuery();

    if (!empty($group_types)) {
      $query->condition('type', $group_types, 'IN');
    }

    if ($published_only) {
      $query->condition('status', 1);
    }

    $query->condition('field_vocab_topics', [$term->id()], 'IN');
    $query->accessCheck(FALSE);
    return $query->execute();
  }

  /**
   * Get group administrators.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The group for which we want to grab the list of administrators.
   *
   * @return \Drupal\group\GroupMembership[]
   *   Array of group admin memberships.
   */
  public static function getGroupAdmins(GroupInterface $group) {
    return $group->getMembers([
      $group->bundle() . '-' . self::GROUP_TYPE_ADMINISTRATOR_ROLE,
    ]);
  }

  /**
   * Format array of a field address to string.
   *
   * @param mixed $address
   *   The address field array.
   *
   * @return string
   *   The formatted address.
   */
  public static function formatAddress($address) {
    $countries_map = CountryManager::getStandardList();
    $location_values = [];
    $location_values[] = $address[FieldHelper::getPropertyName(AddressField::ADDRESS_LINE1)]
      . ' ' .
      $address[FieldHelper::getPropertyName(AddressField::ADDRESS_LINE2)];
    $location_values[] = $address[FieldHelper::getPropertyName(AddressField::POSTAL_CODE)]
      . ' ' .
      $address[FieldHelper::getPropertyName(AddressField::LOCALITY)];
    $location_values[] = array_key_exists(
      $address['country_code'],
      $countries_map
    ) ?
      $countries_map[$address['country_code']] :
      '';

    $location_formatted = '';
    foreach ($location_values as $key => $value) {
      $location_formatted .= $value;
      if (
        isset($location_values[$key + 1]) &&
        !empty(trim($location_formatted)) &&
        !empty(trim($location_values[$key + 1]))
      ) {
        if (!empty(trim($location_values[$key + 1]))) {
          $location_formatted .= '<br>';
        }
      }
    }

    return $location_formatted;
  }

  /**
   * @param \Drupal\Core\Entity\EntityInterface|NULL $entity
   *
   * @return \Drupal\group\Entity\GroupInterface|null
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function getGroupFromContent(?EntityInterface $entity): ?GroupInterface {
    if ($entity instanceof NodeInterface) {
      $group_contents = \Drupal::entityTypeManager()->getStorage('group_content')->loadByEntity($entity);
      if (!empty($group_contents)) {
        /** @var \Drupal\group\Entity\GroupRelationshipInterface $group_content */
        $group_content = reset($group_contents);

        return $group_content->getGroup();
      }
    }

    if ($entity instanceof GroupInterface) {
      return $entity;
    }

    return NULL;
  }

  /**
   * Return if the group itself or content from group is archived.
   *
   * @param \Drupal\Core\Entity\EntityInterface|NULL $entity
   *
   * @return bool
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function isGroupArchived(?EntityInterface $entity) {
    if (!$entity instanceof EntityInterface) {
      return FALSE;
    }

    if ($entity instanceof CommentInterface) {
      $entity = $entity->getCommentedEntity();
    }

    if ($entity instanceof GroupRelationshipInterface) {
      $entity = $entity->getGroup();
    }

    if ($entity instanceof NodeInterface) {
      $group_contents = $this->entityTypeManager->getStorage('group_content')->loadByEntity($entity);

      if (empty($group_contents)) {
        return FALSE;
      }

      /** @var \Drupal\group\Entity\GroupRelationshipInterface $group_content */
      $group_content = reset($group_contents);
      $group = $group_content->getGroup();

      return $group->get('moderation_state')->value === DefaultContentModerationStates::ARCHIVED_STATE;
    }

    if ($entity instanceof GroupInterface) {
      return $entity->get('moderation_state')->value === DefaultContentModerationStates::ARCHIVED_STATE;
    }

    return FALSE;
  }

  /**
   * Get user organisations.
   *
   * @param AccountInterface $account
   *   The user account in which we want to retrieve organisations.
   *
   * @return \Drupal\group\Entity\GroupInterface[]
   *   Array of organisation groups the user belongs to.
   */
  public function getUserOrganisations(AccountInterface $account) {
    /** @var \Drupal\group\Entity\GroupRelationshipInterface[] $user_memberships */
    $user_memberships = $this->entityTypeManager->getStorage('group_content')
      ->loadByProperties([
        'type' => "organisation-group_membership",
        'entity_id' => $account->id(),
      ]);

    // The user doesn't belong to any organisation, so we return empty results.
    if (empty($user_memberships)) {
      return [];
    }

    $user_groups = array_map(function ($user_membership) {
      return $user_membership->getGroup();
    }, $user_memberships);

    return $user_groups;
  }

  /**
   * Gets group bundle label.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The group entity.
   * @param bool $return_plural
   *   Return as plural.
   *
   * @return string
   *   The group label.
   */
  public function getGroupBundleLabel(GroupInterface $group, bool $return_plural = FALSE) {
    $label = $group->type->entity->label();

    if ($return_plural) {
      switch ($group->bundle()) {
        case 'group':
          $label = $this->t('Groups');
          break;

        case 'event':
          $label = $this->t('Events');
          break;

        case 'organisation':
          $label = $this->t('Organisations');
          break;
      }
    }

    return $label;
  }

  /**
   * Gets the enabled joining method for a given group.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The group entity.
   *
   * @return string
   *   The joining method plugin id.
   */
  public function getGroupJoiningMethod(GroupInterface $group) {
    // Joining methods came from oec_group_flex, which this project no longer
    // installs. getGroupJoiningMethod() already returned an empty array for
    // every group -- verified live -- so reset() gave FALSE and this method
    // already returned NULL in every environment.
    return NULL;
  }

}
