
import { ServiceItem, PackageItem, SectorSlug } from './types';

export const SERVICES_CATALOG: ServiceItem[] = [
  { 
    id: 's1', 
    name: 'إدارة حسابات التواصل الاجتماعي', 
    description: 'تهيئة الحسابات، خطة محتوى شهرية، تصميم بوستات وستوريات، ريلز، وإدارة التفاعل والردود.', 
    sectors: ['restaurants', 'ecommerce', 'clinics', 'schools', 'other'], 
    priority: 1 
  },
  { 
    id: 's2', 
    name: 'إدارة الحملات الإعلانية المدفوعة', 
    description: 'إدارة شاملة للحملات على Meta, Snap, TikTok, Google Ads مع تحسين الأداء و A/B Testing.', 
    sectors: ['ecommerce', 'real_estate', 'clinics', 'other'], 
    priority: 2 
  },
  { 
    id: 's3', 
    name: 'الهوية البصرية والبراندنج', 
    description: 'تطوير الشعارات، Brand Guide، وتطبيقات الهوية البصرية المتكاملة.', 
    sectors: ['restaurants', 'real_estate', 'clinics', 'schools', 'other'], 
    priority: 3 
  },
  { 
    id: 's4', 
    name: 'تحسين محركات البحث SEO', 
    description: 'On-page, Technical, Content SEO مع التركيز على تحسين خرائط Google Business Profile.', 
    sectors: ['clinics', 'real_estate', 'schools', 'other'], 
    priority: 4 
  },
  { 
    id: 's5', 
    name: 'المواقع والمتاجر الإلكترونية', 
    description: 'تصميم وتطوير مواقع تعريفية، Landing Pages، ومتاجر إلكترونية (سلة، زد، شوبيفاي).', 
    sectors: ['ecommerce', 'real_estate', 'other'], 
    priority: 5 
  },
  { 
    id: 's6', 
    name: 'أتمتة الواتساب والشات بوت', 
    description: 'بناء شجرة ردود، ربط API (WHSender)، وتحويل المحادثات آلياً.', 
    sectors: ['clinics', 'restaurants', 'ecommerce', 'other'], 
    priority: 6 
  },
  { 
    id: 's7', 
    name: 'تحليلات جوجل GA4 & GTM', 
    description: 'إعداد تتبع التحويلات، Dashboards مخصصة، وتقارير أداء القنوات.', 
    sectors: ['ecommerce', 'other'], 
    priority: 7 
  },
  { 
    id: 's8', 
    name: 'التسويق عبر البريد الإلكتروني', 
    description: 'حملات بريدية ضخمة (حتى 1 مليون) تستهدف الشركات والأفراد بذكاء.', 
    sectors: ['other', 'ecommerce'], 
    priority: 8 
  }
];

export const PACKAGES_CATALOG: PackageItem[] = [
  { 
    id: 'p1', 
    name: 'باقة عروض بداية العام (إدارة التواصل)', 
    price: 2999, 
    originalPrice: 3999, 
    duration: 'شهري',
    scope: ['4 منصات (TikTok, X, IG, Snap)', '20 بوست شهرياً', '10 ستوري', '5 ريلز', 'فيديو موشن/مونتاج'] 
  },
  { 
    id: 'p2', 
    name: 'باقة الأداء الإعلاني (Ads)', 
    price: 1500, 
    duration: 'لكل حملة',
    scope: ['إعداد الاستراتيجية', 'تصميم الإعلانات', 'Tracking Setup (Pixel)', 'تقرير أسبوعي مفصل'] 
  },
  { 
    id: 'p3', 
    name: 'باقة النمو المحلي (Local SEO)', 
    price: 4500, 
    duration: '3 أشهر',
    scope: ['تحسين ملف جوجل بزنس', 'استراتيجية زيادة التقييمات', 'تحليل المنافسين محلياً'] 
  },
  { 
    id: 'p4', 
    name: 'باقة المتجر المتكامل', 
    price: 8500, 
    duration: 'مرة واحدة',
    scope: ['تأسيس متجر سلة/زد', 'رفع 50 منتج', 'ربط الدفع والشحن', 'إعداد التحليلات الأساسية'] 
  }
];

export interface SectorTemplate {
  slug: SectorSlug;
  name: string;
  triggers: string[];
  typical_pains: string[];
  quick_wins: string[];
  default_questions: string[];
}

export const SECTOR_TEMPLATES: SectorTemplate[] = [
  {
    slug: 'restaurants',
    name: 'مطاعم وكافيهات',
    triggers: ['مطعم', 'كافيه', 'كوفي', 'قهوة', 'مقهى', 'كوكتيل', 'حلويات', 'بيتزا', 'برجر', 'مطبخ', 'بوفيه', 'قائمة طعام'],
    typical_pains: ['ضعف الزيارات خارج أوقات الذروة', 'تقييمات Google ضعيفة', 'محتوى غير منتظم'],
    quick_wins: ['تهيئة Google Business Profile', 'عروض وقتية Snap/IG', 'منيو إلكتروني ذكي'],
    default_questions: ['متوسط التذاكر؟', 'هل يوجد توصيل؟', 'أكثر الأطباق ربحية؟']
  },
  {
    slug: 'real_estate',
    name: 'عقارات',
    triggers: ['عقار', 'عقارات', 'وساطة', 'مكتب عقاري', 'تطوير عقاري', 'فلل', 'شقق', 'أراضي', 'إيجار', 'تمليك'],
    typical_pains: ['Leads غير جادة', 'ضعف توحيد الهوية', 'غياب Landing Pages للمشاريع'],
    quick_wins: ['Lead Ads مع فلترة أسئلة', 'Landing مخصصة للمشروع', 'أتمتة واتساب للفرز'],
    default_questions: ['نوع العقار الأساسي؟', 'متوسط الأسعار؟', 'هل يوجد فريق متابعة؟']
  },
  {
    slug: 'ecommerce',
    name: 'تجارة إلكترونية',
    triggers: ['متجر', 'سلة', 'زد', 'شوبيفاي', 'Shopify', 'منتجات', 'Checkout', 'مبيعات'],
    typical_pains: ['زيارات بدون مبيعات', 'Tracking ناقص', 'سلة مهجورة'],
    quick_wins: ['إعداد Pixels & CAPI', 'تحسين صفحات المنتج', 'واتساب سلة مهجورة'],
    default_questions: ['AOV؟', 'أفضل المنتجات مبيعاً؟', 'نسبة السلة المهجورة؟']
  },
  {
    slug: 'clinics',
    name: 'عيادات ومراكز طبية',
    triggers: ['عيادة', 'مجمع طبي', 'أسنان', 'جلدية', 'ليزر', 'تجميل', 'طبي', 'حجز', 'موعد'],
    typical_pains: ['ضعف خرائط Google', 'رد بطيء على الاستفسارات', 'غياب نظام حجز مواعيد'],
    quick_wins: ['Local SEO', 'حملة مواعيد Search', 'واتساب رد آلي وحجز'],
    default_questions: ['الخدمات الأعلى ربحاً؟', 'هل يوجد موظف رد سريع؟', 'ساعات العمل؟']
  },
  {
    slug: 'schools',
    name: 'مدارس وتدريب',
    triggers: ['مدرسة', 'أهلية', 'دولي', 'تسجيل', 'طلاب', 'جامعة', 'معهد', 'دورات', 'تعليم'],
    typical_pains: ['موسم تسجيل قصير', 'نموذج حجز ضعيف', 'رسالة القيمة غير واضحة'],
    quick_wins: ['Landing تسجيل موسمية', 'حملات Snap/Meta', 'واتساب لمتابعة الأهل'],
    default_questions: ['المراحل الدراسية؟', 'الطاقة الاستيعابية؟', 'خصومات التسجيل المبكر؟']
  }
];
