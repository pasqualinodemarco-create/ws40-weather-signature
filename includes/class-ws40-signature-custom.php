<?php
/** Configurable, data-driven WS40 signature renderer (PNG and self-contained SVG). */
if (!defined('ABSPATH')) { exit; }
if (!class_exists('WS40_Signature_Custom', false)) {
final class WS40_Signature_Custom {
    private static function color($o, $key, $fallback) {
        $value = isset($o[$key]) ? (string) $o[$key] : '';
        return preg_match('/^#[0-9a-f]{6}$/i', $value) ? $value : $fallback;
    }
    private static function fields($o, $d, $temp, $wind, $gust, $footer) {
        $labels = array(
            'temperature' => !empty($o['custom_temp_label']) ? $o['custom_temp_label'] : 'TEMPERATURE',
            'humidity' => !empty($o['custom_humidity_label']) ? $o['custom_humidity_label'] : 'HUMIDITY',
            'wind' => !empty($o['custom_wind_label']) ? $o['custom_wind_label'] : 'WIND',
            'gust' => !empty($o['custom_gust_label']) ? $o['custom_gust_label'] : 'MAX WIND / GUST',
        );
        return array(
            'site' => isset($o['site_name']) ? (string)$o['site_name'] : '',
            'domain' => isset($o['domain']) ? (string)$o['domain'] : '',
            'temp' => (string)$temp . '°' . (isset($o['temp_units']) ? $o['temp_units'] : 'C'),
            'humidity' => isset($d['humidity']) && $d['humidity'] !== null ? (string)round($d['humidity']) . '%' : '--',
            'wind' => (!empty($o['show_direction']) && !empty($d['direction']) ? (string)$d['direction'] . ' ' : '') . (string)$wind . ' ' . $o['wind_units'],
            'gust' => (string)$gust . ' ' . $o['wind_units'],
            'footer' => (string)$footer,
            'state' => isset($d['status']) ? $d['status'] : 'offline',
            'labels' => $labels,
        );
    }
    /** Only fetch remotely supplied images via WordPress's safe HTTP transport. */
    private static function remote_image($url) {
        if (!is_string($url) || $url === '' || !function_exists('wp_http_validate_url') || !wp_http_validate_url($url)) return false;
        if (strtolower((string)wp_parse_url($url, PHP_URL_SCHEME)) !== 'https') return false;
        $key = 'ws40_signature_custom_bg_' . md5($url);
        $encoded = get_transient($key);
        $raw = is_string($encoded) ? base64_decode($encoded, true) : false;
        if (!is_string($raw) || $raw === '') {
            $response = wp_safe_remote_get($url, array('timeout'=>5, 'redirection'=>2, 'limit_response_size'=>1572865, 'headers'=>array('Accept'=>'image/png,image/jpeg,image/webp,image/gif')));
            if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) return false;
            $raw = wp_remote_retrieve_body($response);
        }
        if (!is_string($raw) || strlen($raw) < 32 || strlen($raw) > 1572864) return false;
        if (!function_exists('getimagesizefromstring')) return false;
        $info = @getimagesizefromstring($raw);
        if (!$info || !in_array($info[2], array(IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP, IMAGETYPE_GIF), true)) return false;
        if ($info[0] < 16 || $info[1] < 16 || $info[0] > 4096 || $info[1] > 4096 || $info[0]*$info[1] > 6000000) return false;
        $mime = image_type_to_mime_type($info[2]);
        if (!$mime || strpos($mime, 'image/') !== 0) return false;
        if (!is_string($encoded)) set_transient($key, base64_encode($raw), DAY_IN_SECONDS);
        return array('raw'=>$raw,'mime'=>$mime,'width'=>$info[0],'height'=>$info[1]);
    }
    private static function logo($o) {
        $background=self::color($o,'custom_footer','#09263e');
        $r=hexdec(substr($background,1,2)); $g=hexdec(substr($background,3,2)); $b=hexdec(substr($background,5,2));
        $is_dark=(($r*299+$g*587+$b*114)/1000)<135;
        $asset=$is_dark ? 'ws40-logo-footer.png' : 'ws40-meteotemplate-logo.png';
        $path=dirname(__DIR__) . '/assets/' . $asset;
        return is_readable($path) ? $path : false;
    }
    private static function icon($name) {
        $file = dirname(__DIR__) . '/assets/themes/' . $name . '.png';
        return is_readable($file) ? $file : false;
    }
    private static function rgba($im, $hex, $alpha=0) {
        $n = ltrim($hex,'#');
        return imagecolorallocatealpha($im, hexdec(substr($n,0,2)),hexdec(substr($n,2,2)),hexdec(substr($n,4,2)),max(0,min(127,(int)$alpha)));
    }
    private static function box($im,$x,$y,$w,$h,$r,$hex,$alpha=0) {
        $c=self::rgba($im,$hex,$alpha);
        imagefilledrectangle($im,$x+$r,$y,$x+$w-$r,$y+$h,$c);
        imagefilledrectangle($im,$x,$y+$r,$x+$w,$y+$h-$r,$c);
        foreach (array(array($x+$r,$y+$r),array($x+$w-$r,$y+$r),array($x+$r,$y+$h-$r),array($x+$w-$r,$y+$h-$r)) as $p)
            imagefilledellipse($im,$p[0],$p[1],2*$r,2*$r,$c);
    }
    private static function asset_png($im, $path, $x,$y,$w,$h) {
        if (!$path || !function_exists('imagecreatefrompng')) return;
        $src = @imagecreatefrompng($path);
        if (!$src) return;
        imagealphablending($im,true);
        imagecopyresampled($im,$src,$x,$y,0,0,$w,$h,imagesx($src),imagesy($src));
        imagedestroy($src);
    }
    private static function cover_png($im,$raw,$w,$h) {
        if (!function_exists('imagecreatefromstring')) return;
        $src=@imagecreatefromstring($raw);
        if (!$src) return;
        $sw=imagesx($src);$sh=imagesy($src);
        $scale=max($w/$sw,$h/$sh);
        $cw=(int)max(1,round($w/$scale));$ch=(int)max(1,round($h/$scale));
        imagecopyresampled($im,$src,0,0,(int)(($sw-$cw)/2),(int)(($sh-$ch)/2),$w,$h,$cw,$ch);
        imagedestroy($src);
    }
    private static function font($bold=false) {
        static $found=array();
        $key=$bold ? 1 : 0;
        if (array_key_exists($key,$found)) return $found[$key];
        $list=$bold ? array('/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf','/usr/share/fonts/truetype/liberation2/LiberationSans-Bold.ttf','/usr/share/fonts/truetype/lato/Lato-Bold.ttf')
                    : array('/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf','/usr/share/fonts/truetype/liberation2/LiberationSans-Regular.ttf','/usr/share/fonts/truetype/lato/Lato-Regular.ttf');
        if (defined('WP_CONTENT_DIR')) {
            foreach (glob(WP_CONTENT_DIR.'/themes/*/assets/fonts/*.ttf') ?: array() as $path) {
                if ((bool)preg_match('/bold|semibold|heavy/i',basename($path))===(bool)$bold) $list[]=$path;
            }
        }
        foreach ($list as $path) if (is_readable($path)) return $found[$key]=$path;
        return $found[$key]=false;
    }
    private static function width($text,$size,$bold=false) {
        $font=self::font($bold);
        if ($font && function_exists('imagettfbbox')) {
            $bb=@imagettfbbox($size,0,$font,(string)$text);
            if (is_array($bb)) return abs($bb[2]-$bb[0]);
        }
        return strlen((string)$text) * (function_exists('imagefontwidth') ? imagefontwidth(3) : $size * 0.58);
    }
    private static function txt($im,$text,$x,$y,$size,$color,$bold=false,$max=0) {
        $text=(string)$text;
        if ($max) {
            while (self::width($text,$size,$bold)>$max && strlen($text)>1) {
                $text=function_exists('mb_substr') ? mb_substr($text,0,-1,'UTF-8') : substr($text,0,-1);
            }
        }
        $c=self::rgba($im,$color);
        $font=self::font($bold);
        if ($font && function_exists('imagettftext')) @imagettftext($im,$size,0,$x,$y,$c,$font,$text);
        else imagestring($im,$size>=22?5:($size>=14?4:3),$x,max(0,$y-($size>=22?18:12)),preg_replace('/[^\x20-\x7E]/','',$text),$c);
    }
    private static function rounded_corners($im,$w,$h,$radius=13) {
        imagealphablending($im,false);
        $clear=imagecolorallocatealpha($im,0,0,0,127);
        for ($y=0;$y<$radius;$y++) for ($x=0;$x<$radius;$x++) {
            $dx=$radius-$x-1; $dy=$radius-$y-1;
            if ($dx*$dx+$dy*$dy >= $radius*$radius) {
                imagesetpixel($im,$x,$y,$clear);imagesetpixel($im,$w-1-$x,$y,$clear);
                imagesetpixel($im,$x,$h-1-$y,$clear);imagesetpixel($im,$w-1-$x,$h-1-$y,$clear);
            }
        }
        imagealphablending($im,true);
    }
    public static function render_png($layout,$o,$d,$temp,$wind,$gust,$footer) {
        list($w,$h)=($layout==='card') ? array(560,280) : array(900,105);
        $f=self::fields($o,$d,$temp,$wind,$gust,$footer);
        $im=imagecreatetruecolor($w,$h);
        imagealphablending($im,false);imagesavealpha($im,true);
        imagefill($im,0,0,imagecolorallocatealpha($im,0,0,0,127));
        imagealphablending($im,true);
        $bg=self::color($o,'bg','#102b46');
        $photo=!empty($o['bg_image_url']) ? self::remote_image($o['bg_image_url']) : false;
        if (is_array($photo)) self::cover_png($im,$photo['raw'],$w,$h);
        elseif (empty($o['transparent_bg'])) imagefilledrectangle($im,0,0,$w,$h,self::rgba($im,$bg));
        $panel=self::color($o,'custom_panel','#194869');
        $footer_bg=self::color($o,'custom_footer','#09263e');
        $opacity=(int)($o['custom_panel_opacity'] ?? 84);
        $alpha=(int)round(127*(100-max(0,min(100,$opacity)))/100);
        $text=self::color($o,'text','#ffffff');
        $soft=self::color($o,'custom_soft','#c3deea');
        $accent=self::color($o,'accent','#65d6ff');
        $border=self::color($o,'border','#315875');
        if ($layout==='card') {
            self::box($im,13,10,534,47,11,$panel,$alpha);
            self::box($im,13,67,534,172,14,$panel,$alpha);
            self::box($im,13,246,534,28,10,$footer_bg,8);
            self::txt($im,$f['site'],23,32,17,$text,true,330);
            self::txt($im,$f['domain'],23,49,10,$soft,false,420);
            imagefilledrectangle($im,25,160,532,161,self::rgba($im,$border));
            self::asset_png($im,self::icon('suncloud'),25,81,64,64);
            self::txt($im,$f['temp'],100,131,38,$accent,true,185);
            self::txt($im,$f['labels']['temperature'],105,149,10,$soft,true,170);
            if (!empty($o['show_humidity'])) {
                self::asset_png($im,self::icon('drop'),300,90,43,43);
                self::txt($im,$f['labels']['humidity'],352,108,10,self::color($o,'humidity_label_color','#ffffff'),true,172);
                self::txt($im,$f['humidity'],352,137,25,self::color($o,'humidity_value_color','#ffffff'),true,170);
            }
            if (!empty($o['show_wind'])) {
                self::asset_png($im,self::icon('wind'),25,182,41,41);
                self::txt($im,$f['labels']['wind'],73,193,10,self::color($o,'wind_label_color','#ffffff'),true,190);
                self::txt($im,$f['wind'],73,224,20,self::color($o,'wind_value_color','#ffffff'),true,190);
            }
            if (!empty($o['show_gust'])) {
                self::asset_png($im,self::icon('gust'),291,182,41,41);
                self::txt($im,$f['labels']['gust'],336,193,10,self::color($o,'gust_label_color','#ffffff'),true,190);
                self::txt($im,$f['gust'],336,224,20,self::color($o,'gust_value_color','#ffffff'),true,190);
            }
            imagefilledellipse($im,27,260,8,8,self::rgba($im,$f['state']==='online'?'#47dc91':($f['state']==='stale'?'#ffc44c':'#fa697a')));
            self::txt($im,$f['footer'],36,264,10,$soft,false,290);
            self::txt($im,'Powered by',365,264,9,$soft,false,69);
            self::asset_png($im,self::logo($o),436,247,99,26);
        } else {
            self::box($im,12,5,876,27,9,$panel,$alpha);
            self::box($im,12,35,876,46,10,$panel,$alpha);
            self::box($im,12,84,876,19,8,$footer_bg,8);
            self::txt($im,$f['site'],20,24,15,$text,true,540);
            $domain=self::clip_string($f['domain'],11,250);
            self::txt($im,$domain,879-self::width($domain,11),24,11,$soft,false,250);
            foreach (array(277,478,681) as $x) imagefilledrectangle($im,$x,41,$x+1,76,self::rgba($im,$border));
            self::asset_png($im,self::icon('suncloud'),24,43,31,31);
            self::txt($im,$f['temp'],62,66,25,$accent,true,145);
            self::txt($im,$f['labels']['temperature'],64,77,9,$soft,true,165);
            if (!empty($o['show_humidity'])) {
                self::asset_png($im,self::icon('drop'),288,44,30,30);
                self::txt($im,$f['labels']['humidity'],322,52,9,self::color($o,'humidity_label_color','#ffffff'),true,145);
                self::txt($im,$f['humidity'],322,70,17,self::color($o,'humidity_value_color','#ffffff'),true,145);
            }
            if (!empty($o['show_wind'])) {
                self::asset_png($im,self::icon('wind'),489,44,30,30);
                self::txt($im,$f['labels']['wind'],522,52,9,self::color($o,'wind_label_color','#ffffff'),true,143);
                self::txt($im,$f['wind'],522,70,16,self::color($o,'wind_value_color','#ffffff'),true,145);
            }
            if (!empty($o['show_gust'])) {
                self::asset_png($im,self::icon('gust'),692,44,30,30);
                self::txt($im,$f['labels']['gust'],725,52,9,self::color($o,'gust_label_color','#ffffff'),true,142);
                self::txt($im,$f['gust'],725,70,16,self::color($o,'gust_value_color','#ffffff'),true,145);
            }
            imagefilledellipse($im,23,94,7,7,self::rgba($im,$f['state']==='online'?'#47dc91':($f['state']==='stale'?'#ffc44c':'#fa697a')));
            self::txt($im,$f['footer'],32,98,9,$soft,false,580);
            self::txt($im,'Powered by',694,98,9,$soft,false,72);
            self::asset_png($im,self::logo($o),775,81,107,24);
        }
        self::rounded_corners($im,$w,$h);
        imagepng($im);
        imagedestroy($im);
    }
    private static function clip_string($str,$size,$max) {
        $str=(string)$str;
        while (self::width($str,$size)>$max && strlen($str)>1) $str=function_exists('mb_substr') ? mb_substr($str,0,-1,'UTF-8') : substr($str,0,-1);
        return $str;
    }
    public static function render_svg($layout,$o,$d,$temp,$wind,$gust,$footer) {
        list($w,$h)=($layout==='card') ? array(560,280) : array(900,105);
        $f=self::fields($o,$d,$temp,$wind,$gust,$footer);
        $esc=static function($v){return htmlspecialchars((string)$v,ENT_QUOTES|ENT_XML1,'UTF-8');};
        $t=static function($x,$y,$size,$label,$color,$bold=false)use($esc){return '<text x="'.$x.'" y="'.$y.'" font-family="DejaVu Sans,Arial,sans-serif" font-size="'.$size.'" font-weight="'.($bold?'700':'400').'" fill="'.$esc($color).'">'.$esc($label).'</text>';};
        $img=static function($name,$x,$y,$iw,$ih) { $path=self::icon($name);return $path ? '<image x="'.$x.'" y="'.$y.'" width="'.$iw.'" height="'.$ih.'" href="data:image/png;base64,'.base64_encode(file_get_contents($path)).'"/>' : '';};
        $logo=self::logo($o);
        $logo_xml=$logo ? 'data:image/png;base64,'.base64_encode(file_get_contents($logo)) : '';
        $rect=static function($x,$y,$rw,$rh,$radius,$color,$opacity=1)use($esc){return '<rect x="'.$x.'" y="'.$y.'" width="'.$rw.'" height="'.$rh.'" rx="'.$radius.'" fill="'.$esc($color).'" fill-opacity="'.$opacity.'"/>';};
        $bg=self::color($o,'bg','#102b46');$panel=self::color($o,'custom_panel','#194869');
        $foot=self::color($o,'custom_footer','#09263e');$text=self::color($o,'text','#ffffff');
        $soft=self::color($o,'custom_soft','#c3deea');$accent=self::color($o,'accent','#65d6ff');
        $border=self::color($o,'border','#315875');
        $op=max(0,min(100,(int)($o['custom_panel_opacity'] ?? 84)))/100;
        $s='<svg xmlns="http://www.w3.org/2000/svg" width="'.$w.'" height="'.$h.'" viewBox="0 0 '.$w.' '.$h.'"><defs><clipPath id="ws40-custom-round"><rect width="'.$w.'" height="'.$h.'" rx="13"/></clipPath></defs><g clip-path="url(#ws40-custom-round)">';
        $photo=!empty($o['bg_image_url']) ? self::remote_image($o['bg_image_url']) : false;
        if ($photo) $s.='<image x="0" y="0" width="'.$w.'" height="'.$h.'" preserveAspectRatio="xMidYMid slice" href="data:'.$esc($photo['mime']).';base64,'.base64_encode($photo['raw']).'"/>';
        elseif (empty($o['transparent_bg'])) $s.=$rect(0,0,$w,$h,0,$bg);
        $status=$f['state']==='online'?'#47dc91':($f['state']==='stale'?'#ffc44c':'#fa697a');
        if ($layout==='card') {
            $s.=$rect(13,10,534,47,11,$panel,$op).$rect(13,67,534,172,14,$panel,$op).$rect(13,246,534,28,10,$foot,.94);
            $s.=$t(23,32,17,$f['site'],$text,true).$t(23,49,10,$f['domain'],$soft);
            $s.='<line x1="25" y1="160" x2="532" y2="160" stroke="'.$border.'"/>';
            $s.=$img('suncloud',25,81,64,64).$t(100,131,38,$f['temp'],$accent,true).$t(105,149,10,$f['labels']['temperature'],$soft,true);
            if (!empty($o['show_humidity'])) $s.=$img('drop',300,90,43,43).$t(352,108,10,$f['labels']['humidity'],self::color($o,'humidity_label_color','#ffffff'),true).$t(352,137,25,$f['humidity'],self::color($o,'humidity_value_color','#ffffff'),true);
            if (!empty($o['show_wind'])) $s.=$img('wind',25,182,41,41).$t(73,193,10,$f['labels']['wind'],self::color($o,'wind_label_color','#ffffff'),true).$t(73,224,20,$f['wind'],self::color($o,'wind_value_color','#ffffff'),true);
            if (!empty($o['show_gust'])) $s.=$img('gust',291,182,41,41).$t(336,193,10,$f['labels']['gust'],self::color($o,'gust_label_color','#ffffff'),true).$t(336,224,20,$f['gust'],self::color($o,'gust_value_color','#ffffff'),true);
            $s.='<circle cx="27" cy="260" r="4" fill="'.$status.'"/>'.$t(36,264,10,$f['footer'],$soft).$t(365,264,9,'Powered by',$soft);
            if ($logo) $s.='<image x="436" y="247" width="99" height="26" href="'.$logo_xml.'"/>';
        } else {
            $s.=$rect(12,5,876,27,9,$panel,$op).$rect(12,35,876,46,10,$panel,$op).$rect(12,84,876,19,8,$foot,.94);
            $s.=$t(20,24,15,self::clip_string($f['site'],15,540),$text,true).'<text x="879" y="24" text-anchor="end" font-family="DejaVu Sans,Arial,sans-serif" font-size="11" fill="'.$soft.'">'.$esc(self::clip_string($f['domain'],11,250)).'</text>';
            foreach(array(277,478,681) as $x) $s.='<line x1="'.$x.'" y1="41" x2="'.$x.'" y2="76" stroke="'.$border.'"/>';
            $s.=$img('suncloud',24,43,31,31).$t(62,66,25,$f['temp'],$accent,true).$t(64,77,9,$f['labels']['temperature'],$soft,true);
            if (!empty($o['show_humidity'])) $s.=$img('drop',288,44,30,30).$t(322,52,9,$f['labels']['humidity'],self::color($o,'humidity_label_color','#ffffff'),true).$t(322,70,17,$f['humidity'],self::color($o,'humidity_value_color','#ffffff'),true);
            if (!empty($o['show_wind'])) $s.=$img('wind',489,44,30,30).$t(522,52,9,$f['labels']['wind'],self::color($o,'wind_label_color','#ffffff'),true).$t(522,70,16,$f['wind'],self::color($o,'wind_value_color','#ffffff'),true);
            if (!empty($o['show_gust'])) $s.=$img('gust',692,44,30,30).$t(725,52,9,$f['labels']['gust'],self::color($o,'gust_label_color','#ffffff'),true).$t(725,70,16,$f['gust'],self::color($o,'gust_value_color','#ffffff'),true);
            $s.='<circle cx="23" cy="94" r="3.5" fill="'.$status.'"/>'.$t(32,98,9,$f['footer'],$soft).$t(694,98,9,'Powered by',$soft);
            if ($logo) $s.='<image x="775" y="81" width="107" height="24" href="'.$logo_xml.'"/>';
        }
        return $s.'</g></svg>';
    }
}
}
