<?php

declare(strict_types=1);

namespace App\Service\Admin\CustomFields;

use App\Entity\CustomField;
use App\Entity\CustomFieldEnumeration;
use App\I18n\Localizer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The values of a "Key/value list" custom field (Redmine's CustomFieldEnumeration model): adds a value,
 * updates them all at once, and removes one after moving the objects that had it to another value.
 */
final class CustomFieldEnumerationManager
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly Localizer $i18n)
    {
    }

    /** The value of the field with that id (404 when the field has no such value) */
    public function find(CustomField $field, int $id): CustomFieldEnumeration
    {
        foreach ($field->enumerations as $e) {
            if ($e->id === $id) {
                return $e;
            }
        }
        throw new NotFoundHttpException('Not Found');
    }

    /**
     * Adds a value at the end of the list.
     *
     * @param array<string, mixed> $params the custom_field_enumeration[...] parameters
     *
     * @return list<string> the validation errors; empty when the value was added
     */
    public function add(CustomField $field, array $params): array
    {
        $name = trim((string) ($params['name'] ?? ''));
        $errors = [];
        if ('' === $name) {
            $errors[] = $this->i18n->l('field_name').' '.$this->i18n->l('activerecord.errors.messages.blank');
        } elseif (mb_strlen($name) > 255) {
            $errors[] = $this->i18n->l('field_name').' '.$this->i18n->l('activerecord.errors.messages.too_long', ['count' => 255]);
        }
        if ([] !== $errors) {
            return $errors;
        }
        $e = new CustomFieldEnumeration();
        $e->customField = $field;
        $e->name = $name;
        $e->active = !isset($params['active']) || '0' !== (string) $params['active'];
        $max = (int) $this->em->getConnection()->fetchOne('SELECT MAX(position) FROM custom_field_enumerations WHERE custom_field_id = ?', [$field->id]);
        $e->position = $max + 1;
        $field->enumerations->add($e);
        $this->em->persist($e);
        $this->em->flush();

        return [];
    }

    /**
     * Updates the name, state and position of the values; nothing is saved when one of them is invalid.
     *
     * @param array<int|string, mixed> $attrs value id => ['name' => ..., 'active' => ..., 'position' => ...]
     *
     * @return bool whether the values were saved
     */
    public function updateEach(CustomField $field, array $attrs): bool
    {
        $ok = true;
        foreach ($attrs as $id => $values) {
            foreach ($field->enumerations as $e) {
                if ($e->id !== (int) $id || !\is_array($values)) {
                    continue;
                }
                if (\array_key_exists('name', $values)) {
                    $name = trim((string) $values['name']);
                    if ('' === $name || mb_strlen($name) > 255) {
                        $ok = false;
                        break 2;
                    }
                    $e->name = $name;
                }
                if (\array_key_exists('active', $values)) {
                    $v = \is_array($values['active']) ? end($values['active']) : $values['active'];
                    $e->active = '1' === (string) $v;
                }
                if (\array_key_exists('position', $values)) {
                    if (!preg_match('/^-?\d+$/', (string) $values['position'])) {
                        $ok = false;
                        break 2;
                    }
                    $e->position = (int) $values['position'];
                }
            }
        }
        if ($ok) {
            $this->em->flush();
        } else {
            $this->em->clear();
        }

        return $ok;
    }

    /** Number of objects having the value */
    public function objectsCount(CustomField $field, CustomFieldEnumeration $value): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM custom_values WHERE custom_field_id = ? AND value = ?', [$field->id, (string) $value->id]);
    }

    /**
     * Removes the value when no object has it, or when the objects can go to the value named by $reassignToId.
     *
     * @return bool false when objects have the value and no other value of the field was given: nothing was removed
     */
    public function destroyOrReassign(CustomField $field, CustomFieldEnumeration $value, string $reassignToId): bool
    {
        $reassignTo = null;
        foreach ($field->enumerations as $e) {
            if ('' !== $reassignToId && $e->id === (int) $reassignToId) {
                $reassignTo = $e;
            }
        }
        if (null === $reassignTo && $this->objectsCount($field, $value) > 0) {
            return false;
        }
        if (null !== $reassignTo) {
            $this->em->getConnection()->executeStatement('UPDATE custom_values SET value = ? WHERE custom_field_id = ? AND value = ?', [(string) $reassignTo->id, $field->id, (string) $value->id]);
        }
        $field->enumerations->removeElement($value);
        $this->em->remove($value);
        $this->em->flush();

        return true;
    }
}
