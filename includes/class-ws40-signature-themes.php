<?php
/** Dynamic graphic themes for WS40. Independent of the data source. */
if (!defined('ABSPATH')) { exit; }
if (!class_exists('WS40_Signature_Themes', false)) {
final class WS40_Signature_Themes {
    public static function names() {
        return array('light'=>'Light Breeze', 'dark'=>'Midnight Weather', 'minimal'=>'Boletus Forest', 'custom'=>'Custom / Personalizzabile');
    }
    public static function dimensions($layout) {
        return ($layout === 'card') ? array(560,280) : array(900,105);
    }
    private static function palette($style) {
        if ($style === 'dark') return array('text'=>'#f4f9ff','soft'=>'#9ebbd5','accent'=>'#56bafc','humidity'=>'#47b7ff','wind'=>'#43e1b8','gust'=>'#ffc14c','status'=>'#55ee9c','border'=>'#396da0');
        return array('text'=>'#082a55','soft'=>'#5d7897','accent'=>'#1269bd','humidity'=>'#1878ce','wind'=>'#19966a','gust'=>'#df9218','status'=>'#128550','border'=>'#c9e0f1');
    }
    private static function theme_asset($name) {
        return dirname(__DIR__) . '/assets/themes/' . $name . '.png';
    }
    private static function rgb($hex) {
        return array(hexdec(substr($hex,1,2)),hexdec(substr($hex,3,2)),hexdec(substr($hex,5,2)));
    }
    private static function color($im,$hex,$alpha=0) {
        $c=self::rgb($hex);
        return imagecolorallocatealpha($im,$c[0],$c[1],$c[2],$alpha);
    }
    private static function font($bold=false) {
        static $cache = array();
        $key = $bold ? 'bold' : 'regular';
        if (array_key_exists($key,$cache)) return $cache[$key];
        $paths=$bold ? array(
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/truetype/liberation2/LiberationSans-Bold.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
            '/usr/share/fonts/truetype/lato/Lato-Bold.ttf',
            '/usr/share/fonts/TTF/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/DejaVuSans-Bold.ttf',
        ) : array(
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/truetype/liberation2/LiberationSans-Regular.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
            '/usr/share/fonts/truetype/lato/Lato-Regular.ttf',
            '/usr/share/fonts/TTF/DejaVuSans.ttf',
            '/usr/share/fonts/DejaVuSans.ttf',
        );
        // Locate fonts already installed by the host or a WordPress theme.
        $paths=array_merge($paths,$bold ? array(
            '/usr/share/fonts/truetype/roboto/Roboto-Bold.ttf',
            '/usr/share/fonts/truetype/opensans/OpenSans-Bold.ttf',
            '/usr/local/share/fonts/DejaVuSans-Bold.ttf',
        ) : array(
            '/usr/share/fonts/truetype/roboto/Roboto-Regular.ttf',
            '/usr/share/fonts/truetype/opensans/OpenSans-Regular.ttf',
            '/usr/local/share/fonts/DejaVuSans.ttf',
        ));
        if (defined('WP_CONTENT_DIR') && function_exists('glob')) {
            foreach (array('/themes/*/assets/fonts/*.ttf','/themes/*/fonts/*.ttf',
                           '/themes/*/assets/fonts/*.otf','/themes/*/fonts/*.otf') as $pattern) {
                $matches=glob(WP_CONTENT_DIR.$pattern);
                if (!is_array($matches)) continue;
                foreach ($matches as $candidate) {
                    $is_bold=(bool)preg_match('/bold|semibold|heavy|black/i',basename($candidate));
                    if ($is_bold===(bool)$bold) $paths[]=$candidate;
                }
            }
        }
        foreach($paths as $path) if (is_readable($path)) return $cache[$key]=$path;
        $cache[$key]=false;
        return false;
    }
    private static function width($text,$size,$bold=false) {
        $font=self::font($bold);
        if ($font && function_exists('imagettfbbox')) {
            $bbox=@imagettfbbox($size,0,$font,(string)$text);
            if (is_array($bbox)) return abs($bbox[2]-$bbox[0]);
        }
        return strlen((string)$text)*imagefontwidth(4);
    }
    private static function trim_to_width($text,$size,$max,$bold=false) {
        $text=(string)$text;
        if (self::width($text,$size,$bold)<=$max) return $text;
        $len=function_exists('mb_strlen') ? mb_strlen($text,'UTF-8') : strlen($text);
        while ($len>1) {
            $len--;
            $short=function_exists('mb_substr') ? mb_substr($text,0,$len,'UTF-8') : substr($text,0,$len);
            if (self::width($short.'…',$size,$bold)<=$max) return $short.'…';
        }
        return '';
    }
    private static function txt($im,$text,$x,$baseline,$size,$hex,$bold=false,$max_width=0) {
        $font=self::font($bold);
        if ($max_width>0) $text=self::trim_to_width($text,$size,$max_width,$bold);
        $color=self::color($im,$hex);
        if ($font && function_exists('imagettftext')) {
            @imagettftext($im,$size,0,(int)$x,(int)$baseline,$color,$font,(string)$text);
            return;
        }
        // Fonts aren't distributed with this plugin. Basic GD fallback if host lacks system fonts.
        $ascii=preg_replace('/[^\x20-\x7E]/','',(string)$text);
        imagestring($im,$size>=25 ? 5 : ($size>=15 ? 4 : 3),(int)$x,max(0,(int)$baseline-($size>=25?16:12)),$ascii,$color);
    }
    private static function image($im,$asset,$x,$y,$w,$h) {
        $path=self::theme_asset($asset);
        if (!function_exists('imagecreatefrompng') || !is_readable($path)) return;
        $png=@imagecreatefrompng($path);
        if (!$png) return;
        imagealphablending($im,true);
        imagecopyresampled($im,$png,(int)$x,(int)$y,0,0,(int)$w,(int)$h,imagesx($png),imagesy($png));
        imagedestroy($png);
    }
    /** Crop the visual theme art to the banner aspect ratio, without stretching it. */
    private static function banner_background($im,$style,$w,$h) {
        $path=self::theme_asset($style);
        if (!function_exists('imagecreatefrompng') || !is_readable($path)) return;
        $source=@imagecreatefrompng($path);
        if (!$source) return;
        $sw=imagesx($source);$sh=imagesy($source);
        $scale=max($w/$sw,$h/$sh);
        $cropW=max(1,(int)round($w/$scale));$cropH=max(1,(int)round($h/$scale));
        $cropX=max(0,(int)(($sw-$cropW)/2));
        // Keep the upper-middle of scenery visible when reducing banner height.
        $cropY=max(0,(int)(($sh-$cropH)*0.30));
        imagecopyresampled($im,$source,0,0,$cropX,$cropY,$w,$h,$cropW,$cropH);
        imagedestroy($source);
    }
    private static function rect($im,$x,$y,$w,$h,$radius,$color,$alpha) {
        $c=self::color($im,$color,$alpha);
        imagefilledrectangle($im,$x+$radius,$y,$x+$w-$radius,$y+$h,$c);
        imagefilledrectangle($im,$x,$y+$radius,$x+$w,$y+$h-$radius,$c);
        imagefilledellipse($im,$x+$radius,$y+$radius,$radius*2,$radius*2,$c);
        imagefilledellipse($im,$x+$w-$radius,$y+$radius,$radius*2,$radius*2,$c);
        imagefilledellipse($im,$x+$radius,$y+$h-$radius,$radius*2,$radius*2,$c);
        imagefilledellipse($im,$x+$w-$radius,$y+$h-$radius,$radius*2,$radius*2,$c);
    }
    private static function rounded_edges($im,$w,$h,$r=16) {
        imagealphablending($im,false);
        $clear=imagecolorallocatealpha($im,0,0,0,127);
        for ($y=0;$y<$r;$y++) for ($x=0;$x<$r;$x++) {
            $dx=$r-1-$x;$dy=$r-1-$y;
            if ($dx*$dx+$dy*$dy >= $r*$r) {
                imagesetpixel($im,$x,$y,$clear);imagesetpixel($im,$w-1-$x,$y,$clear);
                imagesetpixel($im,$x,$h-1-$y,$clear);imagesetpixel($im,$w-1-$x,$h-1-$y,$clear);
            }
        }
        imagealphablending($im,true);
    }
    private static function content($o,$d,$temp,$wind,$gust,$footer) {
        return array(
            'site'=>isset($o['site_name']) ? $o['site_name'] : 'Weather station',
            'domain'=>isset($o['domain']) ? $o['domain'] : '',
            'temp'=>(string)$temp.'°'.$o['temp_units'],
            'humidity'=>($d['humidity']===null ? '--' : (string)round($d['humidity'])).'%',
            'wind'=>(!empty($o['show_direction']) && !empty($d['direction']) ? $d['direction'].' ' : '').(string)$wind.' '.$o['wind_units'],
            'gust'=>(string)$gust.' '.$o['wind_units'],
            'footer'=>(string)$footer,
            'state'=>isset($d['status']) ? $d['status'] : 'offline',
        );
    }
    /** Boletus Forest: art at the edges, genuine live readings in a legible central panel. */
    private static function forest_asset($layout) {
        return dirname(__DIR__) . '/assets/themes/' . ($layout === 'card' ? 'forest-card.png' : 'forest-banner.png');
    }
    private static function forest_logo_path() {
        return dirname(__DIR__) . '/assets/ws40-logo-footer.png';
    }
    private static function forest_gd_logo($im, $x, $y, $w, $h) {
        $path = self::forest_logo_path();
        if (function_exists('imagecreatefrompng') && is_readable($path)) {
            $logo = @imagecreatefrompng($path);
            if ($logo) {
                imagealphablending($im, true);
                imagecopyresampled($im, $logo, (int)$x, (int)$y, 0, 0, (int)$w, (int)$h, imagesx($logo), imagesy($logo));
                imagedestroy($logo);
                return;
            }
        }
        self::txt($im,'WS40',$x,$y+$h-3,14,'#ffffff',true);
    }
    private static function forest_png($layout,$o,$d,$temp,$wind,$gust,$footer) {
        list($w,$h) = self::dimensions($layout);
        $im = imagecreatetruecolor($w,$h);
        imagealphablending($im,false); imagesavealpha($im,true);
        $transparent = imagecolorallocatealpha($im,0,0,0,127);
        imagefill($im,0,0,$transparent);
        imagealphablending($im,true);
        $asset = self::forest_asset($layout);
        if (is_readable($asset) && function_exists('imagecreatefrompng')) {
            $bg = @imagecreatefrompng($asset);
            if ($bg) {
                imagecopyresampled($im,$bg,0,0,0,0,$w,$h,imagesx($bg),imagesy($bg));
                imagedestroy($bg);
            }
        }
        $c = self::content($o,$d,$temp,$wind,$gust,$footer);
        $forest='#19311e'; $sage='#637052'; $cream='#f8f5e9'; $ivory='#fffbee';
        $status = $c['state'] === 'online' ? '#37d46a' : ($c['state'] === 'stale' ? '#f4c25f' : '#fa7776');
        if ($layout === 'card') {
            self::rect($im,20,11,520,50,13,'#1b3018',29);
            self::rect($im,20,72,520,159,18,$ivory,10);
            self::rect($im,20,239,520,36,12,'#152d16',13);
            self::txt($im,$c['site'],55,36,18,'#ffffff',true,350);
            self::txt($im,$c['domain'],55,51,10,'#e7f0d7',false,390);
            self::image($im,'location',27,17,24,24);
            self::image($im,'suncloud',38,90,61,61);
            self::txt($im,$c['temp'],113,135,39,$forest,true,183);
            self::txt($im,'TEMPERATURE',113,157,11,$sage,true,181);
            if (!empty($o['show_humidity'])) {
                self::image($im,'drop',310,92,48,48);
                self::txt($im,$c['humidity'],365,127,27,'#176dc1',true,152);
                self::txt($im,'HUMIDITY',365,149,11,$sage,true,160);
            }
            imageline($im,39,168,521,168,self::color($im,'#cbc7b7'));
            if (!empty($o['show_wind'])) {
                self::image($im,'wind',39,180,40,40);
                self::txt($im,$c['wind'],88,199,19,'#1f8e57',true,187);
                self::txt($im,'WIND',88,216,10,$sage,true,174);
            }
            if (!empty($o['show_gust'])) {
                self::image($im,'gust',285,180,40,40);
                self::txt($im,$c['gust'],331,199,19,'#d97f10',true,191);
                self::txt($im,'MAX WIND / GUST',331,216,10,$sage,true,188);
            }
            imagefilledellipse($im,40,257,10,10,self::color($im,$status));
            self::txt($im,$c['footer'],52,263,10,'#ffffff',false,273);
            self::txt($im,'Powered by',358,262,9,'#fffcef');
            self::forest_gd_logo($im,425,242,101,31);
        } else {
            self::rect($im,160,3,583,25,10,'#152817',32);
            self::rect($im,158,30,585,49,12,$cream,10);
            self::rect($im,160,81,583,23,10,'#152d16',11);
            self::image($im,'location',170,6,17,17);
            self::txt($im,$c['site'],190,19,13,'#ffffff',true,305);
            $domain=self::trim_to_width($c['domain'],10,200,false);
            self::txt($im,$domain,735-self::width($domain,10,false),19,10,'#ffffff',false,200);
            foreach (array(299,419,570) as $x) imageline($im,$x,37,$x,72,self::color($im,'#c7bfa8'));
            self::image($im,'suncloud',173,38,37,37);
            self::txt($im,$c['temp'],215,59,22,$forest,true,80);
            self::txt($im,'TEMP',215,72,9,$sage,true,77);
            if (!empty($o['show_humidity'])) {
                self::image($im,'drop',307,45,24,25);
                self::txt($im,$c['humidity'],335,58,19,'#137cc9',true,68);
                self::txt($im,'HUMIDITY',335,72,9,$sage,true,75);
            }
            if (!empty($o['show_wind'])) {
                self::image($im,'wind',426,43,26,26);
                self::txt($im,$c['wind'],455,57,16,'#238f59',true,110);
                self::txt($im,'WIND',455,72,9,$sage,true,104);
            }
            if (!empty($o['show_gust'])) {
                self::image($im,'gust',576,43,27,27);
                self::txt($im,$c['gust'],606,57,16,'#ce7611',true,130);
                self::txt($im,'MAX GUST',606,72,9,$sage,true,130);
            }
            imagefilledellipse($im,178,93,8,8,self::color($im,$status));
            self::txt($im,$c['footer'],191,97,10,'#ffffff',false,350);
            self::txt($im,'Powered by',585,97,9,'#fffcef');
            self::forest_gd_logo($im,651,82,82,22);
        }
        self::rounded_edges($im,$w,$h);
        imagepng($im);
        imagedestroy($im);
    }
    /** Exact same theme geometry for no-GD WordPress hosts (SVG fallback). */
    private static function forest_svg($layout,$o,$d,$temp,$wind,$gust,$footer) {
        list($w,$h) = self::dimensions($layout);
        $c=self::content($o,$d,$temp,$wind,$gust,$footer);
        $e = static function($v) { return htmlspecialchars((string)$v, ENT_QUOTES|ENT_XML1,'UTF-8'); };
        $art = self::forest_asset($layout);
        $bg = is_readable($art) ? base64_encode(file_get_contents($art)) : '';
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="'.$w.'" height="'.$h.'" viewBox="0 0 '.$w.' '.$h.'">';
        $svg .= '<defs><clipPath id="ws40-forest-rounded"><rect width="'.$w.'" height="'.$h.'" rx="16"/></clipPath></defs><g clip-path="url(#ws40-forest-rounded)">';
        if ($bg) $svg .= '<image x="0" y="0" width="'.$w.'" height="'.$h.'" preserveAspectRatio="none" xlink:href="data:image/png;base64,'.$bg.'"/>';
        $t = static function($x,$y,$size,$value,$color,$weight='400',$anchor='start') use($e) { return '<text x="'.$x.'" y="'.$y.'" fill="'.$color.'" font-family="Arial,Helvetica,sans-serif" font-weight="'.$weight.'" font-size="'.$size.'" text-anchor="'.$anchor.'">'.$e($value).'</text>'; };
        $icon = static function($name,$x,$y,$iw,$ih) {
            $p=self::theme_asset($name);
            return is_readable($p) ? '<image x="'.$x.'" y="'.$y.'" width="'.$iw.'" height="'.$ih.'" xlink:href="data:image/png;base64,'.base64_encode(file_get_contents($p)).'"/>' : '';
        };
        $logo = static function($x,$y,$iw,$ih) {
            $p=self::forest_logo_path();
            return is_readable($p) ? '<image x="'.$x.'" y="'.$y.'" width="'.$iw.'" height="'.$ih.'" xlink:href="data:image/png;base64,'.base64_encode(file_get_contents($p)).'"/>' : '';
        };
        $status = $c['state']==='online' ? '#37d46a' : ($c['state']==='stale' ? '#f4c25f' : '#fa7776');
        if ($layout==='card') {
            $svg.='<rect x="20" y="11" width="520" height="50" rx="13" fill="#1b3018" fill-opacity=".82"/>';
            $svg.='<rect x="20" y="72" width="520" height="159" rx="18" fill="#fffbee" fill-opacity=".93"/>';
            $svg.='<rect x="20" y="239" width="520" height="36" rx="12" fill="#152d16" fill-opacity=".90"/>';
            $svg.=$icon('location',27,17,24,24).$t(55,36,18,$c['site'],'#ffffff','700').$t(55,51,10,$c['domain'],'#e7f0d7');
            $svg.=$icon('suncloud',38,90,61,61).$t(113,135,39,$c['temp'],'#19311e','700').$t(113,157,11,'TEMPERATURE','#637052','700');
            if(!empty($o['show_humidity'])) $svg.=$icon('drop',310,92,48,48).$t(365,127,27,$c['humidity'],'#176dc1','700').$t(365,149,11,'HUMIDITY','#637052','700');
            $svg.='<line x1="39" y1="168" x2="521" y2="168" stroke="#cbc7b7"/>';
            if(!empty($o['show_wind'])) $svg.=$icon('wind',39,180,40,40).$t(88,199,19,$c['wind'],'#1f8e57','700').$t(88,216,10,'WIND','#637052','700');
            if(!empty($o['show_gust'])) $svg.=$icon('gust',285,180,40,40).$t(331,199,19,$c['gust'],'#d97f10','700').$t(331,216,10,'MAX WIND / GUST','#637052','700');
            $svg.='<circle cx="40" cy="257" r="5" fill="'.$status.'"/>'.$t(52,263,10,$c['footer'],'#ffffff');
            $svg.=$t(358,262,9,'Powered by','#fffcef').$logo(425,242,101,31);
        } else {
            $svg.='<rect x="160" y="3" width="583" height="25" rx="10" fill="#152817" fill-opacity=".78"/>';
            $svg.='<rect x="158" y="30" width="585" height="49" rx="12" fill="#f8f5e9" fill-opacity=".94"/>';
            $svg.='<rect x="160" y="81" width="583" height="23" rx="10" fill="#152d16" fill-opacity=".92"/>';
            $svg.=$icon('location',170,6,17,17).$t(190,19,13,$c['site'],'#ffffff','700').$t(735,19,10,$c['domain'],'#ffffff','400','end');
            foreach(array(299,419,570) as $x) $svg.='<line x1="'.$x.'" y1="37" x2="'.$x.'" y2="72" stroke="#c7bfa8"/>';
            $svg.=$icon('suncloud',173,38,37,37).$t(215,59,22,$c['temp'],'#19311e','700').$t(215,72,9,'TEMP','#637052','700');
            if(!empty($o['show_humidity'])) $svg.=$icon('drop',307,45,24,25).$t(335,58,19,$c['humidity'],'#137cc9','700').$t(335,72,9,'HUMIDITY','#637052','700');
            if(!empty($o['show_wind'])) $svg.=$icon('wind',426,43,26,26).$t(455,57,16,$c['wind'],'#238f59','700').$t(455,72,9,'WIND','#637052','700');
            if(!empty($o['show_gust'])) $svg.=$icon('gust',576,43,27,27).$t(606,57,16,$c['gust'],'#ce7611','700').$t(606,72,9,'MAX GUST','#637052','700');
            $svg.='<circle cx="178" cy="93" r="4" fill="'.$status.'"/>'.$t(191,97,10,$c['footer'],'#ffffff');
            $svg.=$t(585,97,9,'Powered by','#fffcef').$logo(651,82,82,22);
        }
        return $svg.'</g></svg>';
    }

    public static function render_png($style,$layout,$o,$d,$temp,$wind,$gust,$footer) {
        if ($style === 'minimal') { self::forest_png($layout,$o,$d,$temp,$wind,$gust,$footer); return; }
        if ($style === 'custom') { WS40_Signature_Custom::render_png($layout,$o,$d,$temp,$wind,$gust,$footer); return; }
        list($w,$h)=self::dimensions($layout);
        $im=imagecreatetruecolor($w,$h);
        imagealphablending($im,false);imagesavealpha($im,true);
        $clear=imagecolorallocatealpha($im,0,0,0,127);imagefill($im,0,0,$clear);
        imagealphablending($im,true);
        // Use cover-cropping on banners instead of squashing the landscape artwork.
        if ($layout === 'card') self::image($im,$style,0,0,$w,$h);
        else self::banner_background($im,$style,$w,$h);
        $p=self::palette($style);$c=self::content($o,$d,$temp,$wind,$gust,$footer);
        if ($layout==='card') self::draw_card($im,$style,$p,$o,$c,$w,$h);
        else self::draw_banner($im,$style,$p,$o,$c,$w,$h);
        self::rounded_edges($im,$w,$h);
        imagepng($im);imagedestroy($im);
    }
    /** Low-profile 900x105 horizontal signature (formerly 900x210). */
    private static function draw_banner($im,$style,$p,$o,$c,$w,$h) {
        $dark=($style==='dark');
        if ($style==='dark') {
            self::rect($im,290,35,193,45,9,'#04274d',45);
            self::rect($im,492,35,190,45,9,'#043b39',42);
            self::rect($im,691,35,194,45,9,'#553616',51);
        }
        if ($style==='light') self::rect($im,9,84,882,18,7,'#eff7fc',22);
        imageline($im,17,32,$w-17,32,self::color($im,$p['border']));
        if ($dark) self::image($im,'location',16,5,22,22);
        self::txt($im,$c['site'],$dark?44:68,24,15,$p['text'],true,505);
        $domain=self::trim_to_width($c['domain'],11,245,false);
        self::txt($im,$domain,$w-20-self::width($domain,11,false),23,11,$p['accent'],false,250);
        if ($style==='light') foreach(array(288,490,687) as $x)
            imageline($im,$x,38,$x,79,self::color($im,$p['border']));
        self::image($im,'suncloud',22,42,35,35);
        self::txt($im,$c['temp'],70,69,27,$p['text'],true,196);
        $groups=array(
            array('key'=>'show_humidity','title'=>'HUMIDITY','value'=>$c['humidity'],'icon'=>'drop','x'=>296,'hex'=>$p['humidity'],'max'=>147,'size'=>19),
            array('key'=>'show_wind','title'=>'WIND','value'=>$c['wind'],'icon'=>'wind','x'=>496,'hex'=>$p['wind'],'max'=>151,'size'=>17),
            array('key'=>'show_gust','title'=>'MAX WIND / GUST','value'=>$c['gust'],'icon'=>'gust','x'=>692,'hex'=>$p['gust'],'max'=>153,'size'=>17),
        );
        foreach($groups as $g) {
            if (empty($o[$g['key']])) continue;
            self::image($im,$g['icon'],$g['x'],44,28,28);
            self::txt($im,$g['title'],$g['x']+34,49,9,$p['soft'],true,$g['max']);
            self::txt($im,$g['value'],$g['x']+34,69,$g['size'],$g['hex'],true,$g['max']);
        }
        $stateColor=($c['state']==='online') ? $p['status'] : ($c['state']==='stale' ? '#eab856':'#ed6c76');
        imagefilledellipse($im,23,93,7,7,self::color($im,$stateColor));
        self::txt($im,$c['footer'],32,97,9,$p['soft'],false,590);
        if ($style==='dark') self::rect($im,690,79,195,24,12,'#082e59',28);
        else self::rect($im,689,79,198,24,12,'#ffffff',22);
        self::txt($im,'Powered by',704,97,9,$p['soft']);
        $logoPath = $style === 'dark' ? self::forest_logo_path() : dirname(__DIR__).'/assets/ws40-meteotemplate-logo.png';
        $logo=is_readable($logoPath) ? @imagecreatefrompng($logoPath) : false;
        if ($logo) {
            imagecopyresampled($im,$logo,786,80,0,0,92,24,imagesx($logo),imagesy($logo));
            imagedestroy($logo);
        } else self::txt($im,'WS40',804,97,11,$p['accent'],true);
    }
    private static function draw_card($im,$style,$p,$o,$c,$w,$h) {
        $dark=($style==='dark');
        if($dark) {
            self::rect($im,11,9,538,47,15,'#082b56',48);
            self::rect($im,14,69,532,178,18,'#063459',56);
            self::rect($im,14,250,532,25,10,'#072846',44);
        }
        self::txt($im,$c['site'],22,37,18,$p['text'],true,360);
        $domain=self::trim_to_width($c['domain'],11,178,false);
        self::txt($im,$domain,536-self::width($domain,11,false),38,11,$p['accent']);
        imageline($im,22,61,538,61,self::color($im,$p['border']));
        self::image($im,'suncloud',22,79,66,66);
        self::txt($im,$c['temp'],97,136,40,$p['text'],true,182);
        if (!empty($o['show_humidity'])) {
            self::image($im,'drop',310,93,40,40);
            self::txt($im,'HUMIDITY',352,103,11,$p['soft'],true);
            self::txt($im,$c['humidity'],352,133,25,$p['humidity'],true,169);
        }
        imageline($im,25,160,535,160,self::color($im,$p['border']));
        if (!empty($o['show_wind'])) {
            self::image($im,'wind',25,180,44,44);
            self::txt($im,'WIND',76,194,11,$p['soft'],true);
            self::txt($im,$c['wind'],76,224,20,$p['wind'],true,185);
        }
        if (!empty($o['show_gust'])) {
            self::image($im,'gust',285,180,43,43);
            self::txt($im,'MAX WIND / GUST',337,194,10,$p['soft'],true);
            self::txt($im,$c['gust'],337,224,20,$p['gust'],true,186);
        }
        $stateColor=($c['state']==='online')?$p['status']:($c['state']==='stale'?'#eab856':'#ed6c76');
        imagefilledellipse($im,28,264,9,9,self::color($im,$stateColor));
        self::txt($im,$c['footer'],39,267,10,$p['soft'],false,355);
        $logoPath = $style === 'dark' ? self::forest_logo_path() : dirname(__DIR__).'/assets/ws40-meteotemplate-logo.png';
        $logo = is_readable($logoPath) ? @imagecreatefrompng($logoPath) : false;
        if ($logo) {
            imagecopyresampled($im,$logo,468,245,0,0,70,22,imagesx($logo),imagesy($logo));
            imagedestroy($logo);
        } else self::txt($im,'WS40',491,267,13,$p['accent'],true);
    }
    public static function render_svg($style,$layout,$o,$d,$temp,$wind,$gust,$footer) {
        if ($style === 'minimal') return self::forest_svg($layout,$o,$d,$temp,$wind,$gust,$footer);
        if ($style === 'custom') return WS40_Signature_Custom::render_svg($layout,$o,$d,$temp,$wind,$gust,$footer);
        // SVG fallback on servers without GD. Embed local art so the image is self-contained.
        list($w,$h)=self::dimensions($layout);$p=self::palette($style);
        $c=self::content($o,$d,$temp,$wind,$gust,$footer);
        $asset=self::theme_asset($style);$data=is_readable($asset)?base64_encode(file_get_contents($asset)):'';
        $esc=static function($text) {return htmlspecialchars((string)$text,ENT_QUOTES|ENT_XML1,'UTF-8');};
        $s='<svg xmlns="http://www.w3.org/2000/svg" width="'.$w.'" height="'.$h.'" viewBox="0 0 '.$w.' '.$h.'">';
        $s.='<defs><clipPath id="rounded"><rect width="'.$w.'" height="'.$h.'" rx="16"/></clipPath></defs><g clip-path="url(#rounded)">';
        if ($data) $s.='<image x="0" y="0" width="'.$w.'" height="'.$h.'" preserveAspectRatio="xMidYMid slice" href="data:image/png;base64,'.$data.'"/>';

        $t=static function($x,$y,$size,$text,$color,$weight='400')use($esc){return '<text x="'.$x.'" y="'.$y.'" font-family="Arial,Helvetica,sans-serif" font-size="'.$size.'" font-weight="'.$weight.'" fill="'.$color.'">'.$esc($text).'</text>';};
        $icon=static function($name,$x,$y,$iw,$ih) {
            $path=self::theme_asset($name);
            if (!is_readable($path)) return '';
            return '<image x="'.$x.'" y="'.$y.'" width="'.$iw.'" height="'.$ih.'" href="data:image/png;base64,'.base64_encode(file_get_contents($path)).'"/>';
        };
        if ($layout==='card') {
            $s.=$t(23,35,20,$c['site'],$p['text'],'700');$s.=$t(23,57,12,$c['domain'],$p['accent']);
            $s.=$icon('suncloud',22,75,68,68).$t(99,135,42,$c['temp'],$p['text'],'700');
            if (!empty($o['show_humidity'])) $s.=$icon('drop',304,94,43,43).$t(351,104,12,'HUMIDITY',$p['soft']).$t(351,136,26,$c['humidity'],$p['humidity'],'700');
            if (!empty($o['show_wind'])) $s.=$icon('wind',26,178,44,44).$t(77,198,12,'WIND',$p['soft']).$t(77,230,22,$c['wind'],$p['wind'],'700');
            if (!empty($o['show_gust'])) $s.=$icon('gust',286,178,44,44).$t(337,198,12,'MAX WIND / GUST',$p['soft']).$t(337,230,22,$c['gust'],$p['gust'],'700');
            $s.=$t(23,268,11,$c['footer'],$p['soft']);
            $s.='<rect x="398" y="241" width="145" height="26" rx="12" fill="#ffffff" fill-opacity=".55"/>';
            $s.=$t(410,260,10,'Powered by',$p['soft']);
            $logoPath=$style==='dark' ? self::forest_logo_path() : dirname(__DIR__).'/assets/ws40-meteotemplate-logo.png';
            if (is_readable($logoPath)) { $s.='<image x="470" y="243" width="68" height="18" href="data:image/png;base64,'.base64_encode(file_get_contents($logoPath)).'"/>'; }
            else { $s.=$t(500,268,13,'WS40',$p['accent'],'700'); }
        } else {
            $siteText=function_exists('mb_substr')?mb_substr($c['site'],0,56,'UTF-8'):substr($c['site'],0,56);
            $domainText=function_exists('mb_substr')?mb_substr($c['domain'],0,39,'UTF-8'):substr($c['domain'],0,39);
            $s.='<line x1="17" y1="32" x2="883" y2="32" stroke="'.$p['border'].'"/>';
            $s.=$t(20,24,15,$siteText,$p['text'],'700');
            $s.='<text x="881" y="23" text-anchor="end" font-family="Arial" font-size="11" fill="'.$p['accent'].'">'.$esc($domainText).'</text>';
            $s.=$icon('suncloud',22,42,35,35).$t(70,69,27,$c['temp'],$p['text'],'700');
            if (!empty($o['show_humidity'])) $s.=$icon('drop',296,44,28,28).$t(330,49,9,'HUMIDITY',$p['soft'],'700').$t(330,69,19,$c['humidity'],$p['humidity'],'700');
            if (!empty($o['show_wind'])) $s.=$icon('wind',496,44,28,28).$t(530,49,9,'WIND',$p['soft'],'700').$t(530,69,17,$c['wind'],$p['wind'],'700');
            if (!empty($o['show_gust'])) $s.=$icon('gust',692,44,28,28).$t(726,49,9,'MAX WIND / GUST',$p['soft'],'700').$t(726,69,17,$c['gust'],$p['gust'],'700');
            if ($style==='light') foreach(array(288,490,687) as $x) $s.='<line x1="'.$x.'" y1="38" x2="'.$x.'" y2="79" stroke="'.$p['border'].'"/>';
            $statusColor=($c['state']==='online')?$p['status']:(($c['state']==='stale')?'#eab856':'#ed6c76');
            $s.='<circle cx="23" cy="93" r="4" fill="'.$statusColor.'"/>';
            $s.=$t(32,97,10,$c['footer'],$p['soft']);
            $s.='<rect x="689" y="79" width="198" height="24" rx="12" fill="#ffffff" fill-opacity=".55"/>';
            $s.=$t(704,97,10,'Powered by',$p['soft']);
            $logoPath=$style==='dark' ? self::forest_logo_path() : dirname(__DIR__).'/assets/ws40-meteotemplate-logo.png';
            if (is_readable($logoPath)) { $s.='<image x="786" y="80" width="92" height="24" href="data:image/png;base64,'.base64_encode(file_get_contents($logoPath)).'"/>'; } else { $s.=$t(804,97,12,'WS40',$p['accent'],'700'); }
        }
        return $s.'</g></svg>';
    }
}
}
