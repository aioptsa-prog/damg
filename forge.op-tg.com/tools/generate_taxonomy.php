<?php
// Generate a large, balanced taxonomy JSON (>= 2000 nodes) with multilingual names, unique slugs, icons, and leaf keywords.
// Writes to docs/taxonomy_seed.json

require_once __DIR__ . '/../bootstrap.php';

function slugify($s){
  $s = trim(mb_strtolower($s));
  // normalize Arabic/English letters and spaces
  $s = preg_replace('/[\s_]+/u','-',$s);
  $s = preg_replace('/[^\p{L}\p{N}\-]+/u','',$s);
  $s = trim($s,'-');
  if($s==='') $s = 'cat-'.substr(sha1(uniqid('',true)),0,6);
  return $s;
}

function icon_for($domain){
  $map = [
    'technology-it'=>'fa-microchip','medical-health'=>'fa-stethoscope','beauty-care'=>'fa-wand-magic-sparkles','food-restaurants'=>'fa-utensils',
    'public-services'=>'fa-building-columns','government-law'=>'fa-scale-balanced','education-training'=>'fa-graduation-cap','real-estate-construction'=>'fa-city',
    'travel-tourism'=>'fa-plane','media-entertainment'=>'fa-clapperboard','agriculture-farming'=>'fa-wheat-awn','energy-utilities'=>'fa-bolt',
    'automotive-transportation'=>'fa-car','finance-banking'=>'fa-building-columns','retail-ecommerce'=>'fa-store','sports-fitness'=>'fa-dumbbell',
    'arts-culture'=>'fa-palette','home-garden'=>'fa-house-chimney','pets-animals'=>'fa-paw','events-conferences'=>'fa-calendar-check',
    'nonprofit-ngos'=>'fa-hands-holding-heart','science-research'=>'fa-flask','manufacturing-industry'=>'fa-industry',
    'logistics-supply-chain'=>'fa-truck-fast','hr-employment'=>'fa-briefcase','security-defense'=>'fa-shield-halved',
    'environment-sustainability'=>'fa-leaf','hospitality-hotels'=>'fa-hotel','cleaning-maintenance'=>'fa-broom','telecom-networking'=>'fa-tower-cell'
  ];
  return $map[$domain] ?? 'fa-folder';
}

function english_label($s){ return $s; }
function arabic_label($en){
  // Minimal parallel naming where not domain-specific; leave as-is for now
  return $en; // For generator, many names are proper nouns; UI still shows name (ar preferred if present)
}

function make_keywords($arBase, $enBase, $extrasAr=[], $extrasEn=[], $min=5, $max=10){
  $ars = array_values(array_unique(array_filter(array_merge([$arBase], $extrasAr), fn($v)=>is_string($v)&&$v!=='')));
  $ens = array_values(array_unique(array_filter(array_merge([$enBase], $extrasEn), fn($v)=>is_string($v)&&$v!=='')));
  // ensure sizes within bounds
  while(count($ars) < $min){ $ars[] = $arBase; }
  while(count($ens) < $min){ $ens[] = $enBase; }
  if(count($ars) > $max) $ars = array_slice($ars,0,$max);
  if(count($ens) > $max) $ens = array_slice($ens,0,$max);
  $out = [];
  foreach($ars as $a){ $out[] = ['ar'=>$a]; }
  foreach($ens as $e){ $out[] = ['en'=>$e]; }
  return $out;
}

$topDomains = [
  ['slug'=>'technology-it','ar'=>'تقنية واتصالات','en'=>'Technology & IT'],
  ['slug'=>'medical-health','ar'=>'طب وصحة','en'=>'Medical & Health'],
  ['slug'=>'beauty-care','ar'=>'جمال وعناية','en'=>'Beauty & Care'],
  ['slug'=>'food-restaurants','ar'=>'أغذية ومطاعم','en'=>'Food & Restaurants'],
  ['slug'=>'public-services','ar'=>'خدمات عامة','en'=>'Public Services'],
  ['slug'=>'government-law','ar'=>'حكومي وقانون','en'=>'Government & Law'],
  ['slug'=>'education-training','ar'=>'تعليم وتدريب','en'=>'Education & Training'],
  ['slug'=>'real-estate-construction','ar'=>'عقار وإنشاء','en'=>'Real Estate & Construction'],
  ['slug'=>'travel-tourism','ar'=>'سفر وسياحة','en'=>'Travel & Tourism'],
  ['slug'=>'media-entertainment','ar'=>'إعلام وترفيه','en'=>'Media & Entertainment'],
  ['slug'=>'agriculture-farming','ar'=>'زراعة وثروة حيوانية','en'=>'Agriculture & Farming'],
  ['slug'=>'energy-utilities','ar'=>'طاقة ومرافق','en'=>'Energy & Utilities'],
  ['slug'=>'automotive-transportation','ar'=>'سيارات ونقل','en'=>'Automotive & Transportation'],
  ['slug'=>'finance-banking','ar'=>'مال وبنوك','en'=>'Finance & Banking'],
  ['slug'=>'retail-ecommerce','ar'=>'تجزئة وتجارة إلكترونية','en'=>'Retail & E-commerce'],
  ['slug'=>'sports-fitness','ar'=>'رياضة ولياقة','en'=>'Sports & Fitness'],
  ['slug'=>'arts-culture','ar'=>'فن وثقافة','en'=>'Arts & Culture'],
  ['slug'=>'home-garden','ar'=>'منزل وحديقة','en'=>'Home & Garden'],
  ['slug'=>'pets-animals','ar'=>'حيوانات أليفة','en'=>'Pets & Animals'],
  ['slug'=>'events-conferences','ar'=>'فعاليات ومؤتمرات','en'=>'Events & Conferences'],
  ['slug'=>'nonprofit-ngos','ar'=>'جمعيات وخيري','en'=>'Nonprofit & NGOs'],
  ['slug'=>'science-research','ar'=>'علوم وبحث','en'=>'Science & Research'],
  ['slug'=>'manufacturing-industry','ar'=>'تصنيع وصناعة','en'=>'Manufacturing & Industry'],
  ['slug'=>'logistics-supply-chain','ar'=>'لوجستيات وسلاسل إمداد','en'=>'Logistics & Supply Chain'],
  ['slug'=>'hr-employment','ar'=>'موارد بشرية وتوظيف','en'=>'HR & Employment'],
  ['slug'=>'security-defense','ar'=>'أمن ودفاع','en'=>'Security & Defense'],
  ['slug'=>'environment-sustainability','ar'=>'بيئة واستدامة','en'=>'Environment & Sustainability'],
  ['slug'=>'hospitality-hotels','ar'=>'ضيافة وفنادق','en'=>'Hospitality & Hotels'],
  ['slug'=>'cleaning-maintenance','ar'=>'تنظيف وصيانة','en'=>'Cleaning & Maintenance'],
  ['slug'=>'telecom-networking','ar'=>'اتصالات وشبكات','en'=>'Telecommunications & Networking'],
];

// Domain-specific groupings and leaves blueprint to expand to 2000+ nodes
$blueprints = [
  'technology-it' => [
    ['g'=>'Software','ar'=>'برمجيات','icon'=>'fa-code','subs'=>['Web Apps','Mobile Apps','Desktop Apps','SaaS Platforms','DevOps Tools','Security Software','AI/ML','Databases','ERP','CRM']],
    ['g'=>'Hardware','ar'=>'أجهزة','icon'=>'fa-computer','subs'=>['PC Components','Servers','IoT Devices','Peripherals','Storage','Networking Gear']],
    ['g'=>'Services','ar'=>'خدمات','icon'=>'fa-headset','subs'=>['IT Consulting','Managed Services','Cloud Services','Cybersecurity','QA & Testing','Support Desk']],
  ],
  'medical-health' => [
    ['g'=>'Clinics','ar'=>'عيادات','icon'=>'fa-hospital-user','subs'=>['Dental','Dermatology','ENT','Ophthalmology','Pediatrics','OB-GYN','Cardiology','Orthopedics','Neurology','Psychiatry']],
    ['g'=>'Facilities','ar'=>'منشآت','icon'=>'fa-hospital','subs'=>['General Hospital','Specialty Hospital','Day Surgery Center','Medical Labs','Radiology Centers','Pharmacies']],
    ['g'=>'Therapy','ar'=>'علاج','icon'=>'fa-person-walking','subs'=>['Physiotherapy','Occupational Therapy','Speech Therapy','Nutrition & Diet','Rehab Centers']],
  ],
  'beauty-care' => [
    ['g'=>'Salons & Spas','ar'=>'صالونات وسبا','icon'=>'fa-spa','subs'=>['Hair Salons','Barber Shops','Nail Salons','Massage Centers','Moroccan Bath','Skin Care']],
    ['g'=>'Clinics','ar'=>'عيادات','icon'=>'fa-wand-magic-sparkles','subs'=>['Cosmetic Clinics','Laser Centers','Slimming Clinics','Aesthetic Surgery','Dermatology']],
    ['g'=>'Products','ar'=>'منتجات','icon'=>'fa-pump-soap','subs'=>['Skincare','Haircare','Perfumes','Makeup','Organic Beauty']],
  ],
  'food-restaurants' => [
    ['g'=>'Cuisine','ar'=>'مأكولات','icon'=>'fa-bowl-food','subs'=>['Saudi','Levantine','Egyptian','Yemeni','Turkish','Indian','Chinese','Italian','Japanese','Mexican','American','Seafood','BBQ','Vegetarian']],
    ['g'=>'Service Type','ar'=>'نوع الخدمة','icon'=>'fa-utensils','subs'=>['Dine-In','Takeaway','Delivery','Buffet','Cafe','Food Trucks','Bakeries','Desserts']],
  ],
  'public-services' => [
    ['g'=>'Utilities','ar'=>'خدمات','icon'=>'fa-lightbulb','subs'=>['Water','Electricity','Waste Management','Public Transport','Postal Services','Municipality']],
    ['g'=>'Community','ar'=>'مجتمعية','icon'=>'fa-people-group','subs'=>['Libraries','Community Centers','Parks','Museums','Youth Centers']],
  ],
  'government-law' => [
    ['g'=>'Government','ar'=>'حكومي','icon'=>'fa-landmark','subs'=>['Ministries','Courts','Municipalities','Civil Affairs','Passport','Traffic Department']],
    ['g'=>'Legal','ar'=>'قانون','icon'=>'fa-scale-balanced','subs'=>['Law Firms','Notary','Legal Consultants','Arbitration','Intellectual Property']],
  ],
  'education-training' => [
    ['g'=>'Schools','ar'=>'مدارس','icon'=>'fa-school','subs'=>['Kindergarten','Primary','Intermediate','Secondary','International Schools']],
    ['g'=>'Higher Education','ar'=>'تعليم عال','icon'=>'fa-graduation-cap','subs'=>['Universities','Colleges','Institutes','E-Learning Platforms','Scholarships']],
    ['g'=>'Training','ar'=>'تدريب','icon'=>'fa-chalkboard-user','subs'=>['Languages','IT & Programming','Business','Design','Healthcare Training','Vocational']],
  ],
  'real-estate-construction' => [
    ['g'=>'Real Estate','ar'=>'عقار','icon'=>'fa-house','subs'=>['Residential','Commercial','Industrial','Land','Property Management','Real Estate Agencies']],
    ['g'=>'Construction','ar'=>'إنشاء','icon'=>'fa-helmet-safety','subs'=>['Contractors','Architects','Engineers','Materials','Finishing','Interior Design']],
  ],
  'travel-tourism' => [
    ['g'=>'Travel','ar'=>'سفر','icon'=>'fa-plane','subs'=>['Airlines','Travel Agencies','Visa Services','Car Rental','Tour Operators','Cruises']],
    ['g'=>'Tourism','ar'=>'سياحة','icon'=>'fa-earth-asia','subs'=>['Attractions','Guides','Parks','Museums','Adventure Tourism','Religious Tourism']],
  ],
  'media-entertainment' => [
    ['g'=>'Media','ar'=>'إعلام','icon'=>'fa-newspaper','subs'=>['TV Channels','Radio','Newspapers','Magazines','Digital Media']],
    ['g'=>'Entertainment','ar'=>'ترفيه','icon'=>'fa-masks-theater','subs'=>['Cinema','Theaters','Theme Parks','Gaming','Events']],
  ],
  'agriculture-farming' => [
    ['g'=>'Crops','ar'=>'محاصيل','icon'=>'fa-wheat-awn','subs'=>['Grains','Vegetables','Fruits','Date Farms','Greenhouses']],
    ['g'=>'Livestock','ar'=>'ثروة حيوانية','icon'=>'fa-cow','subs'=>['Cattle','Sheep','Poultry','Fish Farms','Beekeeping']],
  ],
  'energy-utilities' => [
    ['g'=>'Energy','ar'=>'طاقة','icon'=>'fa-bolt','subs'=>['Oil & Gas','Solar','Wind','Nuclear','Hydro','Grid']],
    ['g'=>'Utilities','ar'=>'مرافق','icon'=>'fa-solar-panel','subs'=>['Water','Electricity','Waste','District Cooling','Metering']],
  ],
  'automotive-transportation' => [
    ['g'=>'Auto','ar'=>'سيارات','icon'=>'fa-car','subs'=>['Dealers','Repair','Spare Parts','Car Wash','Tires','Battery','Tinting']],
    ['g'=>'Transport','ar'=>'نقل','icon'=>'fa-truck','subs'=>['Taxis','Buses','Logistics','Courier','Freight','Ride-hailing','Metro']],
  ],
  'finance-banking' => [
    ['g'=>'Banking','ar'=>'بنوك','icon'=>'fa-building-columns','subs'=>['Banks','ATMs','Islamic Banking','Loans','Credit Cards']],
    ['g'=>'Finance','ar'=>'مالية','icon'=>'fa-coins','subs'=>['Insurance','Investment','Brokerage','Accounting','FinTech','Payments']],
  ],
  'retail-ecommerce' => [
    ['g'=>'Retail','ar'=>'تجزئة','icon'=>'fa-store','subs'=>['Groceries','Fashion','Electronics','Home Appliances','Furniture','Books','Toys']],
    ['g'=>'E-commerce','ar'=>'تجارة إلكترونية','icon'=>'fa-cart-shopping','subs'=>['Marketplaces','Online Stores','Delivery Apps','Dropshipping','Fulfillment']],
  ],
  'sports-fitness' => [
    ['g'=>'Sports','ar'=>'رياضة','icon'=>'fa-futbol','subs'=>['Clubs','Academies','Stadiums','Coaching','Events']],
    ['g'=>'Fitness','ar'=>'لياقة','icon'=>'fa-dumbbell','subs'=>['Gyms','Personal Training','Yoga','Pilates','CrossFit','Nutrition']],
  ],
  'arts-culture' => [
    ['g'=>'Arts','ar'=>'فن','icon'=>'fa-palette','subs'=>['Galleries','Studios','Workshops','Art Supplies','Photography']],
    ['g'=>'Culture','ar'=>'ثقافة','icon'=>'fa-book','subs'=>['Museums','Heritage','Libraries','Theaters','Festivals']],
  ],
  'home-garden' => [
    ['g'=>'Home','ar'=>'منزل','icon'=>'fa-house','subs'=>['Cleaning','Maintenance','Appliances','Security','Smart Home']],
    ['g'=>'Garden','ar'=>'حديقة','icon'=>'fa-seedling','subs'=>['Landscaping','Plants','Irrigation','Outdoor Furniture','Pools']],
  ],
  'pets-animals' => [
    ['g'=>'Pets','ar'=>'حيوانات أليفة','icon'=>'fa-paw','subs'=>['Pet Shops','Veterinary','Grooming','Boarding','Training']],
    ['g'=>'Animals','ar'=>'حيوانات','icon'=>'fa-hippo','subs'=>['Ranches','Zoos','Wildlife','Birds','Reptiles']],
  ],
  'events-conferences' => [
    ['g'=>'Events','ar'=>'فعاليات','icon'=>'fa-calendar-days','subs'=>['Event Planners','Venues','Exhibitions','Conferences','Weddings','Catering']],
  ],
  'nonprofit-ngos' => [
    ['g'=>'Nonprofits','ar'=>'منظمات','icon'=>'fa-hands-holding-heart','subs'=>['Charities','Foundations','Community Groups','Volunteering','Awareness']],
  ],
  'science-research' => [
    ['g'=>'Science','ar'=>'علوم','icon'=>'fa-flask','subs'=>['Labs','Research Centers','Universities','Conferences','Journals']],
  ],
  'manufacturing-industry' => [
    ['g'=>'Manufacturing','ar'=>'تصنيع','icon'=>'fa-industry','subs'=>['Food Processing','Textiles','Chemicals','Metals','Plastics','Electronics','Automotive Parts']],
  ],
  'logistics-supply-chain' => [
    ['g'=>'Logistics','ar'=>'لوجستيات','icon'=>'fa-truck-fast','subs'=>['Warehousing','Freight Forwarders','Customs','Cold Chain','3PL','Distribution']],
  ],
  'hr-employment' => [
    ['g'=>'HR','ar'=>'موارد بشرية','icon'=>'fa-people-group','subs'=>['Recruitment','Staffing','Payroll','Training','Outsourcing']],
  ],
  'security-defense' => [
    ['g'=>'Security','ar'=>'أمن','icon'=>'fa-shield-halved','subs'=>['Guards','CCTV','Access Control','Cybersecurity','Alarms']],
  ],
  'environment-sustainability' => [
    ['g'=>'Environment','ar'=>'بيئة','icon'=>'fa-leaf','subs'=>['Recycling','Waste Reduction','Water Treatment','Air Quality','Sustainability Consulting']],
  ],
  'hospitality-hotels' => [
    ['g'=>'Hospitality','ar'=>'ضيافة','icon'=>'fa-hotel','subs'=>['Hotels','Resorts','Furnished Apartments','Hostels','Restaurants','Cafes']],
  ],
  'cleaning-maintenance' => [
    ['g'=>'Cleaning','ar'=>'تنظيف','icon'=>'fa-broom','subs'=>['Home Cleaning','Office Cleaning','Deep Cleaning','Pest Control','Sanitization']],
    ['g'=>'Maintenance','ar'=>'صيانة','icon'=>'fa-screwdriver-wrench','subs'=>['HVAC','Electrical','Plumbing','Appliances','Elevators']],
  ],
  'telecom-networking' => [
    ['g'=>'Telecom','ar'=>'اتصالات','icon'=>'fa-tower-cell','subs'=>['Mobile Operators','ISPs','Fiber','Satellite','PBX','Roaming']],
    ['g'=>'Networking','ar'=>'شبكات','icon'=>'fa-network-wired','subs'=>['LAN/WAN','Wi-Fi','Security','SD-WAN','NOC Services']],
  ],
];

$slugSet = [];
$makeSlug = function($s) use (&$slugSet){
  $base = slugify($s); $slug = $base; $i=2;
  while(isset($slugSet[$slug])){ $slug = $base.'-'.$i; $i++; }
  $slugSet[$slug] = true;
  return $slug;
};

function leaf_keywords_for($domain, $group, $leaf){
  $d = mb_strtolower($domain);
  $kwCommonAr = ['أفضل','قريب','أسعار','خدمة','24 ساعة','افتتاح'];
  $kwCommonEn = ['best','near me','prices','service','24h','opening'];
  $extras = [
    'technology-it' => [['ar'=>['تقنية','برمجة','تطوير','خدمات سحابية','دعم'], 'en'=>['technology','software','development','cloud','support']]],
    'medical-health' => [['ar'=>['عيادات','أطباء','مستشفى','رعاية','حجز'], 'en'=>['clinics','doctors','hospital','care','booking']]],
    'beauty-care' => [['ar'=>['صالون','تجميل','ليزر','بشرة','شعر'], 'en'=>['salon','beauty','laser','skin','hair']]],
    'food-restaurants' => [['ar'=>['مطعم','توصيل','قائمة','جلسات','حجز'], 'en'=>['restaurant','delivery','menu','seating','reservation']]],
    'education-training' => [['ar'=>['دورات','تسجيل','مدرس','تعليم','اختبارات'], 'en'=>['courses','enroll','teacher','education','exams']]],
    'retail-ecommerce' => [['ar'=>['متجر','شراء','عروض','طلبات','توصيل'], 'en'=>['store','shop','offers','orders','delivery']]],
  ];
  $ex = $extras[$d][0] ?? ['ar'=>[],'en'=>[]];
  return make_keywords($leaf, $leaf, array_merge([$group,$domain], $ex['ar'], $kwCommonAr), array_merge([$group,$domain], $ex['en'], $kwCommonEn), 6, 12);
}

function expand_domain($domain, $labelAr, $labelEn, $blueprint, $makeSlug){
  $node = [
    'name_ar'=>$labelAr,
    'name_en'=>$labelEn,
    'slug'=>$makeSlug($labelEn),
    'icon'=>['type'=>'fa','value'=>icon_for($domain)],
    'keywords'=>[$labelAr,$labelEn],
    'children'=>[]
  ];
  foreach($blueprint as $grp){
    $gEn = $grp['g']; $gAr = $grp['ar']; $gIcon = $grp['icon']; $subs = $grp['subs'];
    $groupNode = [
      'name_ar'=>$gAr,
      'name_en'=>$gEn,
      'slug'=>$makeSlug($labelEn.' '.$gEn),
      'icon'=>['type'=>'fa','value'=>$gIcon],
      'keywords'=>[$gAr,$gEn],
      'children'=>[]
    ];
    foreach($subs as $sub){
      $subEn = $sub; $subAr = $sub; // keep parallel minimal
      $subNode = [
        'name_ar'=>$subAr,
        'name_en'=>$subEn,
        'slug'=>$makeSlug($labelEn.' '.$gEn.' '.$subEn),
        'icon'=>['type'=>'fa','value'=>$gIcon],
        'keywords'=>[$subAr,$subEn],
        'children'=>[]
      ];
      // Expand leaves into types/variants to grow the tree
      $variants = [
        'Types'=>['Premium','Budget','Family','Luxury','Express','24/7','Women','Men','Kids'],
        'Specialties'=>['Classic','Modern','Advanced','Eco','Smart','Pro','Basic'],
      ];
      foreach($variants as $vName=>$vList){
        $vGroup = [
          'name_ar'=>$vName,
          'name_en'=>$vName,
          'slug'=>$makeSlug($labelEn.' '.$gEn.' '.$subEn.' '.$vName),
          'icon'=>['type'=>'fa','value'=>$gIcon],
          'keywords'=>[$vName,$subEn],
          'children'=>[]
        ];
        foreach($vList as $t){
          $leafEn = $subEn.' '.$t; $leafAr = $leafEn;
          $leaf = [
            'name_ar'=>$leafAr,
            'name_en'=>$leafEn,
            'slug'=>$makeSlug($labelEn.' '.$gEn.' '.$leafEn),
            'icon'=>['type'=>'fa','value'=>$gIcon],
            'keywords'=>leaf_keywords_for($labelEn, $gEn, $leafEn),
          ];
          $vGroup['children'][] = $leaf;
        }
        $subNode['children'][] = $vGroup;
      }
      $groupNode['children'][] = $subNode;
    }
    $node['children'][] = $groupNode;
  }
  return $node;
}

$children = [];
foreach($topDomains as $d){
  $slug = $d['slug'];
  $bp = $blueprints[$slug] ?? [];
  $children[] = expand_domain($slug, $d['ar'], $d['en'], $bp, $makeSlug);
}

$root = [
  'name_ar'=>'جذر',
  'name_en'=>'Root',
  'slug'=>'root',
  'icon'=>['type'=>'fa','value'=>'fa-folder-tree'],
  'keywords'=>[],
  'children'=>$children,
];

// Ensure directory
$target = __DIR__ . '/../docs/taxonomy_seed.json';
if(!is_dir(dirname($target))){ @mkdir(dirname($target), 0777, true); }
file_put_contents($target, json_encode($root, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT));

// Print stats
function count_nodes($n){ $c=1; if(!empty($n['children'])){ foreach($n['children'] as $ch){ $c+=count_nodes($ch);} } return $c; }
$nodes = count_nodes($root);
echo "Generated taxonomy with $nodes nodes\n";
