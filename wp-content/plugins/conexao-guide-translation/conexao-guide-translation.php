<?php
/**
 * Plugin Name: Conexao Guide Translation
 * Description: EN Guide translations (Stage 9).
 * Version: 1.0.0
 * Requires PHP: 7.4
 * Text Domain: conexao-guide-translation
 */
defined( 'ABSPATH' ) || exit;
define( 'CONEXAO_GUIDE_TRANSLATION_FILE', __FILE__ );
define( 'CONEXAO_GUIDE_TRANSLATION_DIR', plugin_dir_path( __FILE__ ) );
require_once CONEXAO_GUIDE_TRANSLATION_DIR . 'includes/terms.php';
require_once CONEXAO_GUIDE_TRANSLATION_DIR . 'includes/builder-1.php';
require_once CONEXAO_GUIDE_TRANSLATION_DIR . 'includes/body-pps-1.php';
require_once CONEXAO_GUIDE_TRANSLATION_DIR . 'includes/body-pps-2.php';
require_once CONEXAO_GUIDE_TRANSLATION_DIR . 'includes/body-medical-1.php';
require_once CONEXAO_GUIDE_TRANSLATION_DIR . 'includes/body-medical-2.php';
require_once CONEXAO_GUIDE_TRANSLATION_DIR . 'includes/body-gpreg.php';
require_once CONEXAO_GUIDE_TRANSLATION_DIR . 'includes/body-bank.php';
require_once CONEXAO_GUIDE_TRANSLATION_DIR . 'includes/body-rent.php';
require_once CONEXAO_GUIDE_TRANSLATION_DIR . 'includes/body-car.php';
require_once CONEXAO_GUIDE_TRANSLATION_DIR . 'includes/body-licence.php';
require_once CONEXAO_GUIDE_TRANSLATION_DIR . 'includes/body-taxes.php';
require_once CONEXAO_GUIDE_TRANSLATION_DIR . 'includes/body-citizenship.php';
require_once CONEXAO_GUIDE_TRANSLATION_DIR . 'includes/body-passport.php';
require_once CONEXAO_GUIDE_TRANSLATION_DIR . 'includes/body-benefits.php';
require_once CONEXAO_GUIDE_TRANSLATION_DIR . 'includes/body-company.php';
require_once CONEXAO_GUIDE_TRANSLATION_DIR . 'includes/body-immigration.php';
require_once CONEXAO_GUIDE_TRANSLATION_DIR . 'includes/body-irp-mygovid.php';
require_once CONEXAO_GUIDE_TRANSLATION_DIR . 'includes/body-rights.php';
require_once CONEXAO_GUIDE_TRANSLATION_DIR . 'includes/body-transport.php';
require_once CONEXAO_GUIDE_TRANSLATION_DIR . 'includes/body-education-emergency.php';
require_once CONEXAO_GUIDE_TRANSLATION_DIR . 'includes/body-nct-motortax.php';
require_once CONEXAO_GUIDE_TRANSLATION_DIR . 'includes/body-eircode.php';
require_once CONEXAO_GUIDE_TRANSLATION_DIR . 'includes/body-housing.php';
require_once CONEXAO_GUIDE_TRANSLATION_DIR . 'includes/body-cao.php';
require_once CONEXAO_GUIDE_TRANSLATION_DIR . 'includes/body-consumer-privacy.php';
require_once CONEXAO_GUIDE_TRANSLATION_DIR . 'includes/body-garda-revenue.php';
require_once CONEXAO_GUIDE_TRANSLATION_DIR . 'includes/guides-b1.php';
require_once CONEXAO_GUIDE_TRANSLATION_DIR . 'includes/guides-b2.php';
require_once CONEXAO_GUIDE_TRANSLATION_DIR . 'includes/guides-b3.php';
require_once CONEXAO_GUIDE_TRANSLATION_DIR . 'includes/body-irp-first.php';
require_once CONEXAO_GUIDE_TRANSLATION_DIR . 'includes/body-irp-travel.php';
require_once CONEXAO_GUIDE_TRANSLATION_DIR . 'includes/body-employment-permit.php';
require_once CONEXAO_GUIDE_TRANSLATION_DIR . 'includes/body-firstjob.php';
require_once CONEXAO_GUIDE_TRANSLATION_DIR . 'includes/body-soletrader.php';
require_once CONEXAO_GUIDE_TRANSLATION_DIR . 'includes/body-hse-care.php';
require_once CONEXAO_GUIDE_TRANSLATION_DIR . 'includes/body-ehic.php';
require_once CONEXAO_GUIDE_TRANSLATION_DIR . 'includes/body-naric.php';
require_once CONEXAO_GUIDE_TRANSLATION_DIR . 'includes/body-leapcard.php';
require_once CONEXAO_GUIDE_TRANSLATION_DIR . 'includes/body-legalaid.php';
require_once CONEXAO_GUIDE_TRANSLATION_DIR . 'includes/body-marriage-birth.php';
require_once CONEXAO_GUIDE_TRANSLATION_DIR . 'includes/body-financial-complaints.php';
require_once CONEXAO_GUIDE_TRANSLATION_DIR . 'includes/body-ombudsman.php';
require_once CONEXAO_GUIDE_TRANSLATION_DIR . 'includes/body-travel-checklist.php';
require_once CONEXAO_GUIDE_TRANSLATION_DIR . 'includes/body-wrc.php';
require_once CONEXAO_GUIDE_TRANSLATION_DIR . 'includes/body-loneparents-1.php';
require_once CONEXAO_GUIDE_TRANSLATION_DIR . 'includes/body-loneparents-2.php';
require_once CONEXAO_GUIDE_TRANSLATION_DIR . 'includes/body-dv-1.php';
require_once CONEXAO_GUIDE_TRANSLATION_DIR . 'includes/body-dv-2.php';
require_once CONEXAO_GUIDE_TRANSLATION_DIR . 'includes/body-dv-3.php';
require_once CONEXAO_GUIDE_TRANSLATION_DIR . 'includes/body-mental-1.php';
require_once CONEXAO_GUIDE_TRANSLATION_DIR . 'includes/body-mental-2.php';
require_once CONEXAO_GUIDE_TRANSLATION_DIR . 'includes/body-mental-3.php';
require_once CONEXAO_GUIDE_TRANSLATION_DIR . 'includes/body-autism-1.php';
require_once CONEXAO_GUIDE_TRANSLATION_DIR . 'includes/body-autism-2.php';
require_once CONEXAO_GUIDE_TRANSLATION_DIR . 'includes/body-fly-1.php';
require_once CONEXAO_GUIDE_TRANSLATION_DIR . 'includes/body-fly-2.php';
require_once CONEXAO_GUIDE_TRANSLATION_DIR . 'includes/body-fly-3.php';
require_once CONEXAO_GUIDE_TRANSLATION_DIR . 'includes/body-learner-permit.php';
require_once CONEXAO_GUIDE_TRANSLATION_DIR . 'includes/guides-d1.php';
require_once CONEXAO_GUIDE_TRANSLATION_DIR . 'includes/guides-d2.php';
require_once CONEXAO_GUIDE_TRANSLATION_DIR . 'includes/guides-a.php';
require_once CONEXAO_GUIDE_TRANSLATION_DIR . 'includes/guides-b.php';
require_once CONEXAO_GUIDE_TRANSLATION_DIR . 'includes/guides-c.php';
require_once CONEXAO_GUIDE_TRANSLATION_DIR . 'includes/guides-d.php';
require_once CONEXAO_GUIDE_TRANSLATION_DIR . 'includes/apply.php';
require_once CONEXAO_GUIDE_TRANSLATION_DIR . 'includes/apply-2a.php';
require_once CONEXAO_GUIDE_TRANSLATION_DIR . 'includes/apply-2b1.php';
require_once CONEXAO_GUIDE_TRANSLATION_DIR . 'includes/apply-2b2.php';
require_once CONEXAO_GUIDE_TRANSLATION_DIR . 'includes/audit.php';
require_once CONEXAO_GUIDE_TRANSLATION_DIR . 'includes/admin.php';
Conexao_Guide_Translation_Admin::init();
