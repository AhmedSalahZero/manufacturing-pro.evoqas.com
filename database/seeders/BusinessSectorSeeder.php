<?php

namespace Database\Seeders;

use App\Models\BusinessSector;
use Illuminate\Database\Seeder;

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — Business sectors (the list on the study form)
//  Location: database/seeders/BusinessSectorSeeder.php
//  Run:  php artisan db:seed --class=BusinessSectorSeeder   (also part of: php artisan db:seed)
//  Safe to run again: a sector is found by its English name and never duplicated.
//  The 24 sectors of the previous ZAVERO. The Arabic names are new (the old list had English in both columns):
//  please have them reviewed.
// ══════════════════════════════════════════════════════════════════

class BusinessSectorSeeder extends Seeder
{
    /** [English name, Arabic name] in the order they are shown. */
    private const SECTORS = [
        ['Textiles & Garments', 'المنسوجات والملابس الجاهزة'],
        ['Automotive', 'السيارات'],
        ['Food & Beverage', 'الأغذية والمشروبات'],
        ['Pharmaceuticals', 'الأدوية'],
        ['Fertilizers', 'الأسمدة'],
        ['Plastics', 'البلاستيك'],
        ['Chemicals', 'الكيماويات'],
        ['Cement', 'الأسمنت'],
        ['Steel', 'الحديد والصلب'],
        ['Glass', 'الزجاج'],
        ['Sanitary Products', 'الأدوات الصحية'],
        ['Ceramics', 'السيراميك'],
        ['Other Construction Materials', 'مواد بناء أخرى'],
        ['Wood & Furniture', 'الأخشاب والأثاث'],
        ['Home Appliance', 'الأجهزة المنزلية'],
        ['Printing & Packaging', 'الطباعة والتغليف'],
        ['Heavy Machinery', 'المعدات الثقيلة'],
        ['Metal Industries & Fabrication', 'الصناعات المعدنية والتصنيع'],
        ['Ready Made Concrete', 'الخرسانة الجاهزة'],
        ['Medical Disposables & Devices', 'المستلزمات والأجهزة الطبية'],
        ['Electronics', 'الإلكترونيات'],
        ['Others', 'أخرى'],
        ['Leather Products', 'المنتجات الجلدية'],
        ['Agro-Processing', 'التصنيع الزراعي'],
    ];

    public function run(): void
    {
        foreach (self::SECTORS as [$english, $arabic]) {
            BusinessSector::updateOrCreate(['name_en' => $english], ['name_ar' => $arabic]);
        }
    }
}
