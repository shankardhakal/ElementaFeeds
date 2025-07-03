<?php

namespace App\Http\Controllers\Admin;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;

class SettingController
{
    public function index()
    {
        // Fetch current settings from the .env file to display in the form
        $settings = [
            'QUEUE_WORKER_COUNT' => env('QUEUE_WORKER_COUNT', 2),
            'QUEUE_WORKER_MEMORY' => env('QUEUE_WORKER_MEMORY', 128),
            'QUEUE_WORKER_MAX_TIME' => env('QUEUE_WORKER_MAX_TIME', 3600),
            'QUEUE_WORKER_SLEEP' => env('QUEUE_WORKER_SLEEP', 3),
            'QUEUE_WORKER_TRIES' => env('QUEUE_WORKER_TRIES', 3),
            'QUEUE_WORKER_TIMEOUT' => env('QUEUE_WORKER_TIMEOUT', 60),
            'QUEUE_WORKER_MAX_JOBS' => env('QUEUE_WORKER_MAX_JOBS', 500),
        ];

        return view('backpack.custom.settings', ['settings' => $settings]);
    }

    public function update(Request $request)
    {
        $this->updateEnvFile($request->except('_token'));

        // After saving, run the update script to apply the changes
        $scriptPath = base_path('update-queue-workers.sh');
        $process = new Process(['sudo', $scriptPath]);
        $process->run();

        if ($process->isSuccessful()) {
            \Alert::success('Settings saved and queue workers have been updated.')->flash();
        } else {
            \Alert::error('Settings saved, but failed to update queue workers. Please run the script manually. Error: ' . $process->getErrorOutput())->flash();
        }

        return redirect()->back();
    }

    private function updateEnvFile(array $data)
    {
        $envFilePath = base_path('.env');
        $content = file_get_contents($envFilePath);

        foreach ($data as $key => $value) {
            $key = strtoupper($key);
            $content = preg_replace("/^{$key}=.*/m", "{$key}={$value}", $content);
        }

        file_put_contents($envFilePath, $content);
        
        // Clear config cache to make sure Laravel uses the new .env values
        Artisan::call('config:clear');
    }
}