<?php

namespace App\Services;

class FilterService
{
    public function passes(array $product, ?array $rules): bool
    {
        if (empty($rules)) {
            return true;
        }

        foreach ($rules as $rule) {
            $field = $rule['field'] ?? null;
            $operator = $rule['operator'] ?? null;
            $value = $rule['value'] ?? null;

            if (!$field || !$operator || !isset($product[$field])) {
                continue; // Skip invalid rule or field not in product
            }

            $productValue = strtolower($product[$field]);
            $ruleValue = strtolower($value);

            $result = match ($operator) {
                'equals' => $productValue == $ruleValue,
                'not_equals' => $productValue != $ruleValue,
                'contains' => str_contains($productValue, $ruleValue),
                'not_contains' => !str_contains($productValue, $ruleValue),
                'greater_than' => (float)$product[$field] > (float)$value,
                'less_than' => (float)$product[$field] < (float)$value,
                'is_empty' => empty($product[$field]),
                'is_not_empty' => !empty($product[$field]),
                default => false,
            };

            // If any rule condition is met, the product passes.
            // This implements an "OR" logic between filter rules.
            // To make it "AND", you would return `false` here if `$result` is false.
            if ($result) {
                return true;
            }
        }

        // If no rules were matched, the product does not pass.
        return false;
    }
}