<?php
require_once __DIR__ . '/includes/header.php';
?>
<main>
    <div style="background: linear-gradient(135deg, var(--charcoal) 0%, var(--dark-gray) 100%); color: white; padding: 60px 20px; border-radius: 8px; text-align: center; margin-bottom: 32px;">
        <h1 style="font-size: 2.5rem; margin: 0 0 16px 0; line-height: 1.2;">Mobile Mechanic</h1>
        <p style="font-size: 1.2rem; margin: 0 0 24px 0; opacity: 0.9;">Emergency Roadside Assistance at Your Location</p>
        <p style="margin: 0 0 32px 0; opacity: 0.85;">Stranded? We're here to help. Professional mechanics available 24/7 to fix your car on the spot.</p>
        <div style="display: flex; gap: 12px; justify-content: center; flex-wrap: wrap;">
            <a class="btn btn-primary" href="<?php echo getBasePath(); ?>customer/request_service.php" style="padding: 14px 32px; font-size: 1.1rem; font-weight: 600;">Request Service Now</a>
            <a class="btn" href="<?php echo getBasePath(); ?>auth/login.php" style="background: rgba(255,255,255,0.2); color: white; border: 2px solid white; padding: 14px 32px; font-size: 1.1rem; font-weight: 600;">Sign In</a>
        </div>
    </div>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 32px;">
        <div class="card" style="text-align: center;">
            <h3 style="color: var(--safety-orange); margin-top: 0;">⚡ Fast Response</h3>
            <p class="muted">Mechanics arrive within 30 minutes of booking.</p>
        </div>
        <div class="card" style="text-align: center;">
            <h3 style="color: var(--safety-orange); margin-top: 0;">🔧 Professional Service</h3>
            <p class="muted">Certified mechanics with years of experience.</p>
        </div>
        <div class="card" style="text-align: center;">
            <h3 style="color: var(--safety-orange); margin-top: 0;">📍 On-Site Repair</h3>
            <p class="muted">We come to you. No need to arrange towing.</p>
        </div>
        <div class="card" style="text-align: center;">
            <h3 style="color: var(--safety-orange); margin-top: 0;">💳 Transparent Pricing</h3>
            <p class="muted">See all costs upfront with detailed invoices.</p>
        </div>
    </div>

    <div class="card" style="background: #f0f4f8; border-left: 4px solid var(--safety-orange);">
        <h2>How It Works</h2>
        <ol style="line-height: 1.8; font-size: 1.05rem;">
            <li><strong>Request Service</strong> — Tell us your location and what's wrong</li>
            <li><strong>We Respond</strong> — A mechanic heads to your location</li>
            <li><strong>Get Fixed</strong> — Professional repair on the spot</li>
            <li><strong>Get Invoice</strong> — Transparent billing with receipt</li>
        </ol>
    </div>

    <div style="text-align: center; margin-top: 32px; padding: 24px; background: #fafafa; border-radius: 8px;">
        <h3>Ready to Get Help?</h3>
        <p class="muted">Don't stay stranded. Request emergency assistance right now.</p>
        <a class="btn btn-primary" href="<?php echo getBasePath(); ?>customer/request_service.php" style="padding: 12px 28px; font-weight: 600;">Request Emergency Service</a>
    </div>
</main>
<?php require_once __DIR__ . '/includes/footer.php';
