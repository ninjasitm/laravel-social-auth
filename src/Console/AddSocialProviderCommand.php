<?php

namespace MadWeb\SocialAuth\Console;

use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;

class AddSocialProviderCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $name = 'social-auth:add';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create social provider in the database.';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $social_model = config('social-auth.models.social');

        $slug = $this->argument('slug');
        $data = [
            'slug' => $slug,
            'label' => ($label = $this->option('label')) ? $label : ucfirst($slug),
        ];

        if ($scopes = $this->option('scopes')) {
            $data['scopes'] = $scopes;
        }

        if ($params = $this->option('params')) {
            foreach ($params as $idx => $param) {
                [$key, $value] = explode(':', $param);
                unset($params[$idx]);
                $params[$key] = $value;
            }

            $data['parameters'] = $params;
        }

        if ($override_scopes = $this->option('override_scopes')) {
            $data['override_scopes'] = $override_scopes;
        }

        if ($stateless = $this->option('stateless')) {
            $data['stateless'] = $stateless;
        }

        $social_model::create($data);

        $this->info("Social provider '$slug' successfully added to the database.");
    }

    /**
     * Get the console command arguments.
     *
     * @return array
     */
    protected function getArguments()
    {
        return [
            ['slug', InputArgument::REQUIRED, 'The name of social provider.'],
        ];
    }

    /**
     * Get the console command options.
     *
     * @return array
     */
    protected function getOptions()
    {
        return [
            ['label', 'l', InputOption::VALUE_OPTIONAL, 'Set label of the social provider.'],

            ['scopes', 's', InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Social provider scopes.'],

            [
                'params',
                'p',
                InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY,
                'Social provider additional parameters.',
            ],

            ['override_scopes', 'o', InputOption::VALUE_NONE, 'Set social provider scopes should override default.'],

            ['stateless', 't', InputOption::VALUE_NONE, 'Social provider should use stateless login.'],
        ];
    }
}
