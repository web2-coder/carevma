<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class BrandingSettingsController extends Controller
{
    private const COLORS = ['primary','secondary','accent','background','text','sidebar','navbar','button','success','warning','error','info','surface','border','muted','sidebar_text','sidebar_active','sidebar_active_text','navbar_text','button_text','input_background'];
    private const MEDIA = ['logo_light','logo_dark','logo_collapsed','favicon','login_background','splash_image','custom_font'];

    private function scopeKey(Request $request, string $name): string
    {
        // Reserved for a verified tenant resolver. Never accept tenant IDs from request data.
        // This installation is single-tenant until the SaaS company/user relationship is integrated.
        return 'white_label:global:'.$name;
    }

    public static function defaults(): array
    {
        $light = ['primary'=>'#0E3180','secondary'=>'#163B49','accent'=>'#2763B8','background'=>'#FFFFFF','text'=>'#111111','sidebar'=>'#FFFFFF','navbar'=>'#FFFFFF','button'=>'#0E3180','success'=>'#16855B','warning'=>'#C17A15','error'=>'#C94949','info'=>'#237DA9','surface'=>'#FFFFFF','border'=>'#DCE7F3','muted'=>'#61748D','sidebar_text'=>'#111111','sidebar_active'=>'#E8F0FF','sidebar_active_text'=>'#111111','navbar_text'=>'#111111','button_text'=>'#FFFFFF','input_background'=>'#FFFFFF'];
        $dark = ['primary'=>'#47C6AA','secondary'=>'#82ABBC','accent'=>'#9FCC3A','background'=>'#0B1720','text'=>'#EDF5F5','sidebar'=>'#07121B','navbar'=>'#12232D','button'=>'#287F70','success'=>'#3BC78A','warning'=>'#F5B94B','error'=>'#FA7373','info'=>'#67BDEB','surface'=>'#152832','border'=>'#334653','muted'=>'#B3C3CF','sidebar_text'=>'#EDF5FA','sidebar_active'=>'#255E76','sidebar_active_text'=>'#FFFFFF','navbar_text'=>'#EDF5F5','button_text'=>'#FFFFFF','input_background'=>'#12232D'];
        return ['mode'=>'light','palettes'=>['light'=>$light,'dark'=>$dark],'font_family'=>'Poppins','spacing'=>'comfortable','radius'=>'comfortable'];
    }

    private function branding(Request $request): array
    {
        return Setting::fetch($this->scopeKey($request, 'branding'), [
            'company_name'=>'CareVMA','tagline'=>'Clinical suite','support_email'=>'','support_phone'=>'','footer_text'=>'',
            'logo_light'=>'','logo_dark'=>'','logo_collapsed'=>'','favicon'=>'','login_background'=>'','splash_image'=>'','custom_font'=>'',
        ]);
    }

    private function theme(Request $request): array
    {
        $stored = Setting::fetch($this->scopeKey($request, 'theme'), self::defaults());
        $defaults = self::defaults();
        if (!is_array($stored)) return $defaults;
        $merged = array_replace_recursive($defaults, $stored);
        // Older records do not have all palette fields: inherit safe defaults.
        return $merged;
    }

    public function publicConfig(Request $request)
    {
        return response()->json(['branding'=>$this->branding($request),'theme'=>$this->theme($request)])->header('Cache-Control','no-store');
    }

    // Personal appearance is scoped exclusively to the authenticated user. This must
    // never mutate the company-wide white_label:global:theme setting.
    private function personalModeKey(Request $request): string
    {
        return 'white_label:user:'.(int) $request->user()->getAuthIdentifier().':mode';
    }

    public function getPersonalThemeMode(Request $request)
    {
        $preference = Setting::fetch($this->personalModeKey($request), ['mode' => 'light']);
        $mode = $preference['mode'] ?? 'light';
        return response()->json(['mode' => in_array($mode, ['light', 'dark'], true) ? $mode : 'light'])
            ->header('Cache-Control', 'no-store, private');
    }

    public function updatePersonalThemeMode(Request $request)
    {
        $validated = $request->validate(['mode' => ['required', 'in:light,dark']]);
        Setting::put($this->personalModeKey($request), ['mode' => $validated['mode']]);
        return response()->json(['mode' => $validated['mode']])->header('Cache-Control', 'no-store, private');
    }

    public function getBranding(Request $request) { return response()->json($this->branding($request)); }
    public function getTheme(Request $request) { return response()->json($this->theme($request)); }

    public function updateBranding(Request $request)
    {
        $v=$request->validate([
            'company_name'=>['sometimes','required','string','max:120'],
            'tagline'=>['sometimes','nullable','string','max:180'],
            'support_email'=>['sometimes','nullable','email','max:190'],
            'support_phone'=>['sometimes','nullable','string','max:40'],
            'footer_text'=>['sometimes','nullable','string','max:240'],
        ]);
        return response()->json(Setting::put($this->scopeKey($request,'branding'),array_merge($this->branding($request),$v)));
    }

    public function updateTheme(Request $request)
    {
        $rules=['mode'=>['required','in:light,dark'], 'font_family'=>['required','in:Poppins,Inter,Roboto,Open Sans,Lato,Montserrat,Nunito,Source Sans 3,Custom'], 'spacing'=>['required','in:compact,comfortable,spacious'], 'radius'=>['required','in:compact,comfortable,spacious'], 'palettes'=>['required','array'], 'palettes.light'=>['required','array'], 'palettes.dark'=>['required','array']];
        foreach (['light','dark'] as $mode) foreach(self::COLORS as $color) $rules['palettes.'.$mode.'.'.$color]=['required','regex:/^#[0-9A-Fa-f]{6}$/D'];
        $v=$request->validate($rules);
        $safe=['mode'=>$v['mode'],'font_family'=>$v['font_family'],'spacing'=>$v['spacing'],'radius'=>$v['radius'],'palettes'=>[]];
        foreach(['light','dark'] as $mode) foreach(self::COLORS as $color) $safe['palettes'][$mode][$color]=$v['palettes'][$mode][$color];
        return response()->json(Setting::put($this->scopeKey($request,'theme'),$safe));
    }

    public function resetTheme(Request $request)
    {
        return response()->json(Setting::put($this->scopeKey($request,'theme'),self::defaults()));
    }

    public function upload(Request $request, string $kind)
    {
        abort_unless(in_array($kind,self::MEDIA,true),404);
        $font=$kind==='custom_font';
        $rules=$font?['required','file','mimes:woff,woff2,ttf,otf','max:2048']:['required','image','mimes:png,jpg,jpeg,webp,gif','max:4096'];
        if($kind==='favicon') $rules=['required','image','mimes:png,jpg,jpeg,webp','max:1024'];
        $request->validate(['file'=>$rules]);
        $file=$request->file('file');
        if(!$font) {
            $dimensions=@getimagesize($file->getRealPath());
            if(!$dimensions) throw ValidationException::withMessages(['file'=>'Invalid image.']);
            if($dimensions[0]>6000 || $dimensions[1]>6000) throw ValidationException::withMessages(['file'=>'Maximum image dimension is 6000px.']);
        }
        $path=$file->store('branding/'.$kind,'public');
        $branding=$this->branding($request);
        $branding[$kind]=Storage::disk('public')->url($path);
        if ($kind==='logo_light') { $branding['logo_dark']=''; $branding['logo_collapsed']=''; }
        Setting::put($this->scopeKey($request,'branding'),$branding);
        return response()->json(['url'=>$branding[$kind],'width'=>$font?null:$dimensions[0], 'height'=>$font?null:$dimensions[1], 'warning'=>!$font && str_starts_with($kind,'logo') && ($dimensions[0]<300 || $dimensions[1]<300)?'Logo is under the recommended 300×300px for a 150px display and may look blurry.':null]);
    }

    public function removeMainLogo(Request $request)
    {
        $branding=$this->branding($request);
        $branding['logo_light']='';
        $branding['logo_dark']='';
        $branding['logo_collapsed']='';
        // Keep uploaded files on disk for safe rollback; remove only references.
        Setting::put($this->scopeKey($request,'branding'),$branding);
        return response()->json($branding);
    }

    public function profile(Request $request)
    {
        $u=$request->user();
        return response()->json(['name'=>$u->name,'email'=>$u->email,'avatar'=>$u->profile_picture,'preferences'=>Setting::fetch('white_label:user:'.$u->id, ['phone'=>'','designation'=>'','locale'=>'en','timezone'=>'UTC','notifications'=>true])]);
    }

    public function updateProfile(Request $request)
    {
        $v=$request->validate(['name'=>['required','string','max:120'],'phone'=>['nullable','string','max:40'],'designation'=>['nullable','string','max:120'],'locale'=>['required','in:en,ur,es,fr,ar'],'timezone'=>['required','timezone'],'notifications'=>['required','boolean']]);
        $u=$request->user();$u->update(['name'=>$v['name']]);
        $prefs=collect($v)->except('name')->all();
        Setting::put('white_label:user:'.$u->id,$prefs);
        return response()->json(['message'=>'Profile saved','preferences'=>$prefs]);
    }

    public function avatar(Request $request)
    {
        $request->validate(['avatar'=>['required','image','mimes:png,jpg,jpeg,webp','max:3072']]);
        $path=$request->file('avatar')->store('users/profile_pictures','public');
        $url=Storage::disk('public')->url($path);
        // Do not delete previous avatar until the new file is securely stored.
        $request->user()->forceFill(['profile_picture'=>$url])->save();
        return response()->json(['url'=>$url]);
    }

    public function password(Request $request)
    {
        $v=$request->validate(['current_password'=>['required','string'],'password'=>['required','confirmed',Password::min(12)->mixedCase()->numbers()]]);
        if(!Hash::check($v['current_password'],$request->user()->password)) throw ValidationException::withMessages(['current_password'=>'Current password is incorrect.']);
        $request->user()->password=$v['password'];$request->user()->save();
        return response()->json(['message'=>'Password updated']);
    }
}
