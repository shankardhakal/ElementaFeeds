<?php

namespace App\Services\Api;

interface ApiClientInterface
{
    public function getCategories(): array;
    public function getAttributes(): array;
}