<?php

namespace Drupal\odoo_sync\Traits;

use Drupal\Core\Language\LanguageInterface;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeTypeInterface;
use Drupal\user\Entity\User;

/**
 * Trait NodeType Creation Trait.
 *
 * @package Drupal\base_master_global\Tests\Traits
 */
trait NodeTypeCreationTrait {

  /**
   * Create Node Type.
   *
   * @param string|null $name
   *   NodeType Name.
   * @param string|null $nid
   *   NodeType ID.
   *
   * @return \Drupal\Core\Entity\EntityBase|\Drupal\Core\Entity\EntityInterface|null
   *   Get the Node Type.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  private function createNodeType($name, $nid, $description = "") {
    if($name && $nid) {
      $nodeType = NodeType::load($nid);
      if (!$nodeType) {
        $nodeType = NodeType::create([
          'name' => $name,
          'description' => $description,
          'type' => $nid,
          'langcode' => LanguageInterface::LANGCODE_NOT_SPECIFIED,
          'weight' => mt_rand(0, 10),
        ]);
        $nodeType->save();
      }
      return $nodeType;
    }
    return NULL;
  }

  /**
   * Create Node with given Type.
   *
   * @param \Drupal\node\NodeTypeInterface $nodeType
   *   Node Type.
   * @param array $values
   *   List of values/fields.
   *
   * @return \Drupal\Core\Entity\EntityBase|\Drupal\Core\Entity\EntityInterface
   *   Get the Node.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  protected function createNode(NodeTypeInterface $nodeType, array $values = []) {

    // Populate defaults array.
    $values += [
      'body' => [
        [
          'value' => $this->randomMachineName(32),
          'format' => filter_default_format(),
        ],
      ],
      'title' => $this->randomMachineName(8),
      'type' => $nodeType->id(),
    ];

    if (!array_key_exists('uid', $values)) {
      $user = User::load(\Drupal::currentUser()->id());
      if ($user) {
        $values['uid'] = $user->id();
      }
      elseif (method_exists($this, 'setUpCurrentUser')) {
        /** @var \Drupal\user\UserInterface $user */
        $user = $this->setUpCurrentUser();
        $values['uid'] = $user->id();
      }
      else {
        $values['uid'] = 0;
      }
    }

    $node = Node::create($values);
    $node->save();

    return $node;
  }

}
