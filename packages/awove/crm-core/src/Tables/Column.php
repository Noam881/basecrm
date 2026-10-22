<?php

namespace Awove\Core\Tables;

final readonly class Column
{
    public function __construct(public string $id, public string $label, public string $expression, public string $type = 'text', public string $group = 'Record', public array $options = [], public int $width = 180) {}

    public function metadata(): array
    {
        return ['id' => $this->id, 'label' => $this->label, 'type' => $this->type, 'group' => $this->group, 'options' => $this->options, 'width' => $this->width];
    }

    public function operators(): array
    {
        return match ($this->type) {
            'number','date','datetime' => ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'between', 'empty', 'not_empty'],'select','boolean' => ['eq', 'neq', 'in', 'empty', 'not_empty'],default => ['contains', 'not_contains', 'eq', 'neq', 'starts', 'ends', 'empty', 'not_empty']
        };
    }
}
