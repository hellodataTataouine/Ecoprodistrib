<?php

namespace App\Http\Controllers\User;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Http\Helpers\KreativMailer;
use App\User;
use Auth;
use Illuminate\Support\Facades\Mail;
use Session;
use App\Language;
use Config;
use App\BasicSetting as BS;
use App\BasicExtended as BE;
use App\BasicExtra;

class RegisterController extends Controller
{

    public function __construct()
    {
        $bs = BS::first();
        $be = BE::first();

        Config::set('captcha.sitekey', $bs->google_recaptcha_site_key);
        Config::set('captcha.secret', $bs->google_recaptcha_secret_key);
    }

    public function registerPage()
    {
        $bex = BasicExtra::first();

        if ($bex->is_user_panel == 0) {
            return back();
        }

        if (session()->has('lang')) {
            $currentLang = Language::where('code', session()->get('lang'))->first();
        } else {
            $currentLang = Language::where('is_default', 1)->first();
        }

        $be = $currentLang->basic_extended;
        $version = $be->theme_version;

        if ($version == 'dark') {
            $version = 'default';
        }

        $data['version'] = $version;

        return view('front.register', $data);

    }

    public function register(Request $request)
    {

        if (session()->has('lang')) {
            $currentLang = Language::where('code', session()->get('lang'))->first();
        } else {
            $currentLang = Language::where('is_default', 1)->first();
        }

        $bs = $currentLang->basic_setting;
        $be = $currentLang->basic_extended;

        // $messages = [
        //     'g-recaptcha-response.required' => 'Please verify that you are not a robot.',
        //     'g-recaptcha-response.captcha' => 'Captcha error! try again later or contact site admin.',
        // ];

        $rules = [
            'email'   => 'required|email|unique:users',
            'username' => 'required|unique:users',
            'password' => 'required|confirmed'
        ];

        // if ($bs->is_recaptcha == 1) {
        //     $rules['g-recaptcha-response'] = 'required|captcha';
        // }

        $request->validate($rules);

        $user = new User;
        $input = $request->all();
        $input['status'] = 1;
        $input['password'] = bcrypt($request['password']);
        $token = md5(time().$request->name.$request->email);
        $input['verification_link'] = $token;
        $user->fill($input)->save();


        // $mailer = new KreativMailer();
        // $data = [
        //     'toMail' => $user->email,
        //     'toName' => $user->username,
        //     'customer_username' => $user->username,
        //     'verification_link' => "<a href='" . url('register/verify/' . $token) . "'>" . url('register/verify/' . $token) . "</a>",
        //     'website_title' => $bs->website_title,
        //     'templateType' => 'email_verification',
        //     'type' => 'emailVerification'
        // ];
        // $mailer->mailFromAdmin($data);
        $to = $user->email;
        $verification_link = url('register/verify/' . $token);
        $subject = "Vérification de l'E-mail";
        $data['toname']=$user->username;
        $data['verification_link']=$verification_link;

        $this->sendEmail($to,$subject,$data);

        return back()->with('sendmail','Nous devons vérifier votre adresse e-mail. Nous avons envoyé un e-mail à  '.$request->email. ' pour vérifier votre adresse e-mail, veuillez cliquer sur le lien dans cet e-mail pour continuer.');

    }
    public function sendEmail($to, $subject, $data)
    {
        Mail::send("email_verification",$data, function ($message) use ($to, $subject) {
            $message->to($to)
                    ->subject($subject);
        });
    }

    public function token($token)
    {
        $user = User::where('verification_link',$token)->first();
        if(isset($user))
        {
            $user->email_verified = 'Yes';
            $user->update();
            Auth::guard('web')->login($user);
            Session::flash('success', 'Email Verified Successfully');
            return redirect()->route('user-dashboard');
        }
    }

}
