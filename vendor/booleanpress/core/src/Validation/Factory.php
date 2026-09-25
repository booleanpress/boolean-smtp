<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Validation;

use BooleanSmtp\Core\Foundation\Application;

class Factory
{
    protected Application $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function make(array $data, array $rules): Validator
    {
        return new Validator($data, $rules);
    }
}
