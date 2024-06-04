<?php

namespace App\Http\Controllers;

use App\PaymentGateway;
use Illuminate\Http\Request;
use Artisan;

class UpdateController extends Controller
{
    public function version()
    {
        return view('updater.version');
    }

    public function recurse_copy($src, $dst)
    {

        $dir = opendir(base_path($src));
        @mkdir(base_path($dst));
        while (false !== ($file = readdir($dir))) {
            if (($file != '.') && ($file != '..')) {
                if (is_dir(base_path($src) . '/' . $file)) {
                    $this->recurse_copy($src . '/' . $file, $dst . '/' . $file);
                } else {
                    copy(base_path($src) . '/' . $file, base_path($dst) . '/' . $file);
                }
            }
        }
        closedir($dir);
    }

    public function upversion(Request $request)
    {
        $assets = array(
            ['path' => 'assets/front/js/shop-checkout-stripe.js', 'type' => 'file', 'action' => 'add'],
            ['path' => 'assets/front/js/package-checkout-stripe.js', 'type' => 'file', 'action' => 'add'],
            ['path' => 'assets/front/js/course-checkout-stripe.js', 'type' => 'file', 'action' => 'add'],
            ['path' => 'assets/front/js/donation-checkout-stripe.js', 'type' => 'file', 'action' => 'add'],
            ['path' => 'assets/front/js/event-checkout-stripe.js', 'type' => 'file', 'action' => 'add'],

            ['path' => 'config', 'type' => 'folder', 'action' => 'replace'],
            ['path' => 'resources/views', 'type' => 'folder', 'action' => 'replace'],
            ['path' => 'routes/web.php', 'type' => 'file', 'action' => 'replace'],
            ['path' => 'app', 'type' => 'folder', 'action' => 'replace'],
            ['path' => 'composer.json', 'type' => 'file', 'action' => 'replace'],
            ['path' => 'composer.lock', 'type' => 'file', 'action' => 'replace'],

            ['path' => 'version.json', 'type' => 'file', 'action' => 'replace']
        );

        foreach ($assets as $key => $asset) {
            // if updater need to replace files / folder (with/without content)
            if ($asset['action'] == 'replace') {
                if ($asset['type'] == 'file') {
                    copy(base_path('../updater/' . $asset["path"]), base_path($asset["path"]));
                }
                if ($asset['type'] == 'folder') {
                    $this->delete_directory($asset["path"]);
                    $this->recurse_copy('../updater/' . $asset["path"], $asset["path"]);
                }
            }
            // if updater need to add files / folder (with/without content)
            elseif ($asset['action'] == 'add') {
                if ($asset['type'] == 'folder') {
                    $this->recurse_copy('../updater/' . $asset["path"], '../' . $asset["path"]);
                }
                if ($asset['type'] == 'file') {
                    copy(base_path('../updater/' . $asset["path"]), base_path('../' . $asset["path"]));
                }
            }
        }
        @mkdir(base_path('public'));


        Artisan::call('config:clear');
        // run migration files
        Artisan::call('migrate');
        $payumoney = PaymentGateway::where('keyword', 'payumoney')->first();
        $payumoney->status = 0;
        $payumoney->save();


        \Session::flash('success', 'Updated successfully');
        return redirect('updater/success.php');
    }

    function delete_directory($dirname)
    {
        $dir_handle = null;
        if (is_dir($dirname))
            $dir_handle = opendir($dirname);

        if (!$dir_handle)
            return false;
        while ($file = readdir($dir_handle)) {
            if ($file != "." && $file != "..") {
                if (!is_dir($dirname . "/" . $file))
                    unlink($dirname . "/" . $file);
                else
                    $this->delete_directory($dirname . '/' . $file);
            }
        }
        closedir($dir_handle);
        rmdir($dirname);
        return true;
    }


    public function redirectToWebsite(Request $request)
    {
        $arr = ['WEBSITE_HOST' => $request->website_host];
        setEnvironmentValue($arr);
        \Artisan::call('config:clear');

        return redirect()->route('front.index');
    }
}
