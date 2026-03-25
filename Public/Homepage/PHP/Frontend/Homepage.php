<?php session_start(); ?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />

    <title>ScanQuotient | Homepage</title>

    <link rel="icon" type="image/png" href="../../../../Storage/Public_images/page_icon.png" />

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" />

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link
        href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@300;400;500;600;700&family=Inter:wght@300;400;500;600;700&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="../../CSS/homepage.css" />

    <script>
        window.PHP_FEEDBACK = <?php echo json_encode($_SESSION['feedback_status'] ?? ''); ?>;
        <?php unset($_SESSION['feedback_status']); ?>
    </script>

    <script src="../../Javascript/homepage.js" defer></script>
</head>

<body>
    <div class="bg-grid"></div>

    <!-- Toast Notification -->
    <div id="toast" class="toast">Feedback submitted successfully!</div>

    <!-- Floating Glass Header -->
    <header class="header" id="header">
        <div class="brand-container">
            <a href="#home" class="brand">ScanQuotient</a>
            <div class="tagline">Quantifying Risk.Strengthening Security.</div>
        </div>

        <nav>
            <ul class="nav-links" id="navLinks">
                <li><a href="#home">Home</a></li>
                <li><a href="#features">Features</a></li>
                <li><a href="#services">Services</a></li>
                <li><a href="#how-it-works">Process</a></li>
                <li><a href="#pricing">Pricing</a></li>
                <li><a href="#about-us">About</a></li>
                <li><a href="#contact-us">Contact</a></li>
            </ul>
            <div class="hamburger" onclick="toggleMobileMenu()">☰</div>
        </nav>

        <div class="profile-container" style="display: flex; align-items: center; gap: 10px;">
            <!-- Theme Toggle -->
            <button class="theme-toggle-btn" id="themeToggle" aria-label="Toggle Theme" data-tooltip="Change Theme">
                <i class="fas fa-moon" id="themeIcon"></i>
            </button>

            <a href="../../../Help_center/PHP/Frontend/Help_center.php" class="help-btn" aria-label="Get Help"
                title="Help & Support">
                <i class="fas fa-question-circle"></i>
            </a><br>

            <button class="sign-in-btn"
                onclick="window.location.href='/ScanQuotient.v2/ScanQuotient.B/Public/Login_page/PHP/Frontend/Login_page_site.php'">
                <i class="fas fa-sign-in-alt"></i> Sign In
            </button>
        </div>
    </header>

    <!-- Hero Section with Cinematic Image -->
    <section class="hero" id="home">
        <div class="hero-bg"></div>
        <div class="hero-overlay"></div>

        <div class="hero-content">
            <div class="hero-text">
                <div class="hero-badge">
                    <i class="fas fa-user-shield"></i> Welcome to ScanQuotient
                </div>
                <h1 class="hero-title">
                    Identify Threats.<br>
                    <span>Quantify Risk.</span><br>
                    Secure Everything.
                </h1>
                <p class="hero-subtitle">
                    Simple vulnerability assessment platform engineered for modern SMEs.
                    Automated security scanning, intelligent risk scoring, and actionable
                    intelligence all in one unified command center.
                </p>
                <div class="hero-buttons">
                    <a href="../../../Registration_page/PHP/Frontend/Registration_page.php" class="btn btn-primary">
                        <i class="fas fa-shield-alt"></i> Start Free Scan
                    </a>
                    <a href="#features" class="btn btn-secondary">
                        <i class="fas fa-play-circle"></i> Watch Demo
                    </a>
                </div>
            </div>

            <div class="hero-visual">
                <div class="security-card">
                    <div
                        style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                        <div>
                            <div style="color: var(--text-muted); font-size: 0.875rem;">Security Score</div>
                            <div style="font-size: 2rem; font-weight: 700; color: var(--primary);">94.2%</div>
                        </div>
                        <i class="fas fa-shield-check" style="font-size: 2.5rem; color: var(--primary);"></i>
                    </div>
                    <div class="scan-line"></div>
                    <div
                        style="display: flex; justify-content: space-between; margin-bottom: 10px; font-size: 0.875rem; color: var(--text-muted);">
                        <span>Scanning target...</span>
                        <span>https://example.com </span>
                    </div>
                    <div class="stats-row">
                        <div class="stat-item">
                            <div class="stat-value">0</div>
                            <div class="stat-label">Critical</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value">2</div>
                            <div class="stat-label">High</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value">5</div>
                            <div class="stat-label">Medium</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Features Section -->
    <section id="features">
        <div class="container">
            <div class="section-header">
                <span class="section-eyebrow">Capabilities</span>
                <h2 class="section-title">Comprehensive Security Arsenal</h2>
                <p class="section-subtitle">
                    Six key assessment modules working together to find vulnerabilities before attackers can.
                </p>
            </div>

            <div class="features-grid">
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-bug"></i>
                    </div>
                    <h3 class="feature-title">Vulnerability Scanning</h3>
                    <p class="feature-description">
                        Intelligently detects SQL Injection and XSS, two vulnerabilities from the OWASP Top 10, using
                        precise and reliable testing methods..
                    </p>
                </div>

                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-fingerprint"></i>
                    </div>
                    <h3 class="feature-title">HTTP Header Analysis</h3>
                    <p class="feature-description">
                        Deep inspection of security headers including CSP, HSTS, X-Frame-Options,
                        and custom policy configurations.
                    </p>
                </div>

                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-lock"></i>
                    </div>
                    <h3 class="feature-title">SSL/TLS Verification</h3>
                    <p class="feature-description">
                        Complete certificate validation and encryption protocol analysis ensuring
                        bulletproof data transmission standards.
                    </p>
                </div>

                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-network-wired"></i>
                    </div>
                    <h3 class="feature-title">Network Port Scanning</h3>
                    <p class="feature-description">
                        Comprehensive port discovery and service identification to eliminate
                        unnecessary attack surface exposure.
                    </p>
                </div>

                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-chart-pie"></i>
                    </div>
                    <h3 class="feature-title">Intelligent Risk Scoring</h3>
                    <p class="feature-description">
                        CVSS-based severity classification with contextual business impact
                        assessment for prioritized remediation.
                    </p>
                </div>

                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-file-contract"></i>
                    </div>
                    <h3 class="feature-title">Executive Security Reports</h3>
                    <p class="feature-description">
                        Board-ready PDF reports with technical findings, risk matrices, and
                        actionable remediation roadmaps.
                    </p>
                </div>
            </div>
        </div>
    </section>

    <!-- Services Section -->
    <section id="services"
        style="background: linear-gradient(180deg, transparent, rgba(112, 0, 255, 0.03), transparent);">
        <div class="container">
            <div class="section-header">
                <span class="section-eyebrow">Modules</span>
                <h2 class="section-title">Specialized Assessment Services</h2>
                <p class="section-subtitle">
                    Targeted security modules designed for specific assessment requirements
                    and compliance standards.
                </p>
            </div>

            <div class="services-grid">
                <div class="service-card" onclick="openModal('web')">
                    <div class="service-number">01</div>
                    <h3 class="service-title">Web Application Scanning</h3>
                    <p class="service-description">
                        Comprehensive OWASP-based testing for web applications, APIs, and
                        microservices architectures.
                    </p>
                    <a class="service-link">
                        Explore Module <i class="fas fa-arrow-right"></i>
                    </a>
                </div>

                <div class="service-card" onclick="openModal('config')">
                    <div class="service-number">02</div>
                    <h3 class="service-title">Configuration Review</h3>
                    <p class="service-description">
                        Server hardening assessment and security header optimization for
                        infrastructure resilience.
                    </p>
                    <a class="service-link">
                        Explore Module <i class="fas fa-arrow-right"></i>
                    </a>
                </div>

                <div class="service-card" onclick="openModal('ssl')">
                    <div class="service-number">03</div>
                    <h3 class="service-title">SSL/TLS & Network</h3>
                    <p class="service-description">
                        Encryption standard verification and network exposure analysis for
                        complete attack surface mapping.
                    </p>
                    <a class="service-link">
                        Explore Module <i class="fas fa-arrow-right"></i>
                    </a>
                </div>
            </div>
        </div>
    </section>

    <!-- How It Works -->
    <section id="how-it-works">
        <div class="container">
            <div class="section-header">
                <span class="section-eyebrow">Workflow</span>
                <h2 class="section-title">Three Steps to Security Clarity</h2>
                <p class="section-subtitle">
                    Streamlined assessment process from initial scan to executive reporting
                    in minutes, not days.
                </p>
            </div>

            <div class="timeline">
                <div class="timeline-item">
                    <div class="timeline-dot"></div>
                    <div class="timeline-content">
                        <div class="step-number">1</div>
                        <h3 style="font-size: 1.5rem; margin-bottom: 12px; color: var(--text-main);">Platform Access
                        </h3>
                        <p style="color: var(--text-muted); line-height: 1.7;">
                            Instant account provisioning with role-based access controls.
                            Configure your authorized testing environment.
                        </p>
                    </div>
                </div>

                <div class="timeline-item">
                    <div class="timeline-dot"></div>
                    <div class="timeline-content">
                        <div class="step-number">2</div>
                        <h3 style="font-size: 1.5rem; margin-bottom: 12px; color: var(--text-main);">Target Assessment
                        </h3>
                        <p style="color: var(--text-muted); line-height: 1.7;">
                            Input target URL. Our engine executes parallel
                            vulnerability tests, configuration audits, and encryption checks.
                        </p>
                    </div>
                </div>

                <div class="timeline-item">
                    <div class="timeline-dot"></div>
                    <div class="timeline-content">
                        <div class="step-number">3</div>
                        <h3 style="font-size: 1.5rem; margin-bottom: 12px; color: var(--text-main);">Intelligence
                            Delivery</h3>
                        <p style="color: var(--text-muted); line-height: 1.7;">
                            Receive prioritized findings with CVSS scores, remediation
                            guidance, and compliance mapping within minutes.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Testimonials -->
    <section id="testimonials"
        style="background: linear-gradient(180deg, transparent, rgba(0, 240, 255, 0.03), transparent);">
        <div class="container">
            <div class="section-header">
                <span class="section-eyebrow">Testimonials</span>
                <h2 class="section-title">Trusted by Security Teams</h2>
                <p class="section-subtitle">
                    See how organizations are transforming their security posture with
                    ScanQuotient.
                </p>
            </div>

            <div class="testimonials-container">
                <div class="testimonial-card">
                    <p class="testimonial-text">
                        ScanQuotient transformed our security workflow. The automated scanning
                        identified critical vulnerabilities our manual audits missed, and the
                        risk scoring helped us prioritize fixes effectively.
                    </p>
                    <div class="testimonial-author">
                        <div class="author-avatar">JD</div>
                        <div class="author-info">
                            <h4>James Davidson</h4>
                            <p>CTO, TechStart Nairobi</p>
                        </div>
                    </div>
                </div>

                <div class="testimonial-card">
                    <p class="testimonial-text">
                        Finally, a security tool that speaks business language. The executive
                        reports are board-ready, and the technical details are precise enough
                        for our dev team to act immediately.
                    </p>
                    <div class="testimonial-author">
                        <div class="author-avatar">SM</div>
                        <div class="author-info">
                            <h4>Sarah Mitchell</h4>
                            <p>Security Lead, Innovate Solutions</p>
                        </div>
                    </div>
                </div>

                <div class="testimonial-card">
                    <p class="testimonial-text">
                        As a cybersecurity student, ScanQuotient provided hands-on experience
                        with enterprise-grade tools. The structured reports taught me how to
                        communicate findings professionally.
                    </p>
                    <div class="testimonial-author">
                        <div class="author-avatar">AK</div>
                        <div class="author-info">
                            <h4>Alex Kimani</h4>
                            <p>Cyber-seC Student,UON</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Pricing -->
    <section id="pricing">
        <div class="container">
            <div class="section-header">
                <span class="section-eyebrow">Investment</span>
                <h2 class="section-title">Transparent Pricing</h2>
                <p class="section-subtitle">
                    Scale your security testing from startup to enterprise without hidden fees
                    or complex contracts.
                </p>
            </div>

            <div class="pricing-grid">
                <div class="pricing-card">
                    <h3 class="pricing-name">Starter</h3>
                    <div class="pricing-price">Free<span>/forever</span></div>
                    <p class="pricing-description">Perfect for individual developers and small projects.</p>
                    <ul class="pricing-features">
                        <li><i class="fas fa-check"></i> 5 scan reports storage</li>
                        <li><i class="fas fa-check"></i> Basic vulnerability scanning</li>
                        <li><i class="fas fa-check"></i> Standard risk scoring</li>
                        <li><i class="fas fa-check"></i> Email support</li>
                        <li class="disabled"><i class="fas fa-times"></i> AI-assisted analysis</li>
                    </ul>
                    <a href="../../../Registration_page/PHP/Frontend/Registration_page.php" class="btn btn-secondary"
                        style="width: 100%; justify-content: center;">
                        Get Started
                    </a>
                </div>

                <div class="pricing-card featured">
                    <div class="pricing-badge">Most Popular</div>
                    <h3 class="pricing-name">Professional</h3>
                    <div class="pricing-price">$10<span>/month</span></div>
                    <p class="pricing-description">For growing teams requiring unlimited assessments.</p>
                    <ul class="pricing-features">
                        <li><i class="fas fa-check"></i> Unlimited scan storage</li>
                        <li><i class="fas fa-check"></i> Advanced scanning modules priority</li>
                        <li><i class="fas fa-check"></i> Priority queue processing</li>
                        <li><i class="fas fa-check"></i> Export to PDF/CSV</li>
                        <li class="disabled"><i class="fas fa-times"></i> AI remediation</li>
                    </ul>
                    <a href="../../../Payment/PHP/Frontend/Payment_page.php" class="btn btn-primary"
                        style="width: 100%; justify-content: center;">
                        Upgrade Now
                    </a>
                </div>

                <div class="pricing-card">
                    <h3 class="pricing-name">Enterprise</h3>
                    <div class="pricing-price">$25<span>/month</span></div>
                    <p class="pricing-description">Advanced intelligence for security-conscious organizations.</p>
                    <ul class="pricing-features">
                        <li><i class="fas fa-check"></i> Everything in Pro</li>
                        <li><i class="fas fa-check"></i> AI vulnerability analysis</li>
                        <li><i class="fas fa-check"></i> Remediation guidance</li>
                        <li><i class="fas fa-check"></i> Compliance reporting</li>
                        <li><i class="fas fa-check"></i> 24/7 priority support</li>
                    </ul>
                    <a href="../../../Payment/PHP/Frontend/Payment_page.php" class="btn btn-primary"
                        style="width: 100%; justify-content: center;">
                        Upgrade Now
                    </a>
                </div>
            </div>
        </div>
    </section>

    <!-- Partners Marquee -->
    <section class="partners-section" id="trusted-businesses">
        <div class="container" style="margin-bottom: 40px;">
            <div class="section-header" style="margin-bottom: 40px;">
                <span class="section-eyebrow">Partners</span>
                <h2 class="section-title" style="font-size: 1.5rem;">Trusted by Industry Leaders</h2>
            </div>
        </div>
        <div class="partners-track">
            <span class="partner-logo">Innovate Solutions</span>
            <span class="partner-logo">Global Merchants Co.</span>
            <span class="partner-logo">Swift E-Com</span>
            <span class="partner-logo">Apex Retail Group</span>
            <span class="partner-logo">Digital Growth Hub</span>
            <span class="partner-logo">Horizon Commerce</span>
            <span class="partner-logo">Future Market Pros</span>
            <span class="partner-logo">TechStart Nairobi</span>
            <span class="partner-logo">Safari Digital</span>
            <span class="partner-logo">Nairobi Fintech</span>
            <!-- Duplicate for seamless loop -->
            <span class="partner-logo">Innovate Solutions</span>
            <span class="partner-logo">Global Merchants Co.</span>
            <span class="partner-logo">Swift E-Com</span>
            <span class="partner-logo">Apex Retail Group</span>
            <span class="partner-logo">Digital Growth Hub</span>
            <span class="partner-logo">Horizon Commerce</span>
            <span class="partner-logo">Future Market Pros</span>
            <span class="partner-logo">TechStart Nairobi</span>
            <span class="partner-logo">Safari Digital</span>
            <span class="partner-logo">Nairobi Fintech</span>
        </div>
    </section>

    <!-- CTA Section -->
    <section class="cta-section">
        <div class="container cta-content">
            <h2 class="cta-title">Ready to Eliminate Security Blind Spots?</h2>
            <p
                style="font-size: 1.25rem; color: var(--text-muted); max-width: 600px; margin: 0 auto 40px; line-height: 1.8;">
                Join hundreds of organizations using ScanQuotient to identify vulnerabilities,
                quantify risks, and strengthen their security posture.
            </p>
            <a href="../../../Registration_page/PHP/Frontend/Registration_page.php" class="btn btn-primary"
                style="font-size: 1.125rem; padding: 20px 40px;">
                <i class="fas fa-shield-alt"></i> Start Your Free Assessment
            </a>
            <p style="margin-top: 20px; color: var(--text-muted); font-size: 0.875rem;">
                No credit card required • Setup in 60 seconds
            </p>
        </div>
    </section>

    <!-- About Section -->
    <section id="about-us">
        <div class="container">
            <div class="section-header">
                <span class="section-eyebrow">Mission</span>
                <h2 class="section-title">Redefining Web Security Assessment</h2>
            </div>

            <div class="about-grid">
                <div class="about-text">
                    <p style="font-size: 1.25rem; color: var(--text-main); margin-bottom: 24px; line-height: 1.8;">
                        ScanQuotient was born from a simple observation: enterprise-grade security
                        testing shouldn't require enterprise budgets or expertise.
                    </p>

                    <p style="color: var(--text-muted); margin-bottom: 20px; line-height: 1.8;">
                        We built a platform that democratizes vulnerability assessment, making
                        advanced security testing accessible to SMEs, startups, and educational
                        institutions across Africa and beyond.
                    </p>

                    <h3>Our Vision</h3>
                    <p style="color: var(--text-muted); margin-bottom: 20px; line-height: 1.8;">
                        A digital ecosystem where every organization, regardless of size, can
                        proactively identify and remediate security vulnerabilities before
                        exploitation.
                    </p>

                    <h3>Our Mission</h3>
                    <p style="color: var(--text-muted); margin-bottom: 20px; line-height: 1.8;">
                        Deliver modular, reliable, and educational security assessment tools
                        that bridge the gap between complex enterprise solutions and basic
                        manual testing.
                    </p>

                    <div class="about-quote">
                        "Security is not a product, but a continuous process of assessment,
                        adaptation, and improvement." -Mwangi Ndekere, Founder & CEO
                    </div>
                </div>

                <div class="about-visual">
                    <div class="about-image"></div>
                    <div
                        style="position: absolute; bottom: -20px; right: -20px; background: var(--glass); border: 1px solid var(--glass-border); border-radius: 16px; padding: 20px; backdrop-filter: blur(10px);">
                        <div style="font-size: 2rem; font-weight: 700; color: var(--primary);">500+</div>
                        <div style="color: var(--text-muted); font-size: 0.875rem;">Assessments Completed</div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Contact Section -->
    <section id="contact-us"
        style="background: linear-gradient(180deg, transparent, rgba(112, 0, 255, 0.03), transparent);">
        <div class="container">
            <div class="section-header">
                <span class="section-eyebrow">Connect</span>
                <h2 class="section-title">Let's Start a Conversation</h2>
                <p class="section-subtitle">
                    Have questions about our platform? Need a custom enterprise solution?
                    Our security experts are here to help.
                </p>
            </div>

            <div class="contact-grid">
                <div class="contact-info">
                    <div class="contact-card">
                        <div class="contact-icon">
                            <i class="fas fa-phone-alt"></i>
                        </div>
                        <div class="contact-details">
                            <h4>Phone</h4>
                            <p>(+254) 115-520-624</p>
                        </div>
                    </div>

                    <div class="contact-card">
                        <div class="contact-icon">
                            <i class="fas fa-envelope"></i>
                        </div>
                        <div class="contact-details">
                            <h4>Email</h4>
                            <a href="mailto:scanquotient@gmail.com">scanquotient@gmail.com</a>
                        </div>
                    </div>

                    <div class="contact-card">
                        <div class="contact-icon">
                            <i class="fas fa-map-marker-alt"></i>
                        </div>
                        <div class="contact-details">
                            <h4>Location</h4>
                            <p>Nairobi, Kenya</p>
                        </div>
                    </div>

                    <div class="contact-card">
                        <div class="contact-icon" style="background: #25d366;">
                            <i class="fab fa-whatsapp" style="color: #fff;"></i>
                        </div>
                        <div class="contact-details">
                            <h4>WhatsApp</h4>
                            <a href="https://wa.me/254115520624 " target="_blank">+254 115-520-624</a>
                        </div>
                    </div>

                    <div class="contact-card" style="flex-direction: column; align-items: flex-start;">
                        <h4 style="margin-bottom: 16px; color: var(--text-main);">Follow Us</h4>
                        <div class="social-links" style="position: static;">
                            <a href="https://twitter.com/elevatecomai " target="_blank"><i
                                    class="fab fa-twitter"></i></a>
                            <a href="https://facebook.com/elevatecomai " target="_blank"><i
                                    class="fab fa-facebook-f"></i></a>
                            <a href="https://linkedin.com/company/elevatecomai " target="_blank"><i
                                    class="fab fa-linkedin-in"></i></a>
                            <a href="https://instagram.com/elevatecomai " target="_blank"><i
                                    class="fab fa-instagram"></i></a>
                        </div>
                    </div>
                </div>

                <div class="contact-form-wrapper">
                    <h3 style="font-size: 1.5rem; margin-bottom: 24px; color: var(--text-main);">Send Message</h3>
                    <form action="../Backend/submit_customer_feedback.php" method="POST">
                        <div class="form-group">
                            <input type="text" name="name" placeholder="Your Name" required>
                        </div>
                        <div class="form-group">
                            <input type="email" name="email" placeholder="Your Email" required>
                        </div>
                        <div class="form-group">
                            <input type="text" name="subject" placeholder="Subject">
                        </div>
                        <div class="form-group">
                            <textarea name="message" placeholder="Your Message" required></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center;">
                            <i class="fas fa-paper-plane"></i> Send Message
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer>
        <div class="container">
            <div class="footer-grid">
                <div class="footer-brand">
                    <a href="#home" class="brand">ScanQuotient</a>
                    <p>Quantifying Risk. Strengthening Security. Empowering SME's with intelligent vulnerability
                        assessment.</p>
                    <div class="social-links">
                        <a href="https://twitter.com/elevatecomai " target="_blank"><i class="fab fa-twitter"></i></a>
                        <a href="https://facebook.com/elevatecomai " target="_blank"><i
                                class="fab fa-facebook-f"></i></a>
                        <a href="https://linkedin.com/company/elevatecomai " target="_blank"><i
                                class="fab fa-linkedin-in"></i></a>
                        <a href="https://instagram.com/elevatecomai " target="_blank"><i
                                class="fab fa-instagram"></i></a>
                        <a href="https://wa.me/254115520624 " target="_blank"><i class="fab fa-whatsapp"></i></a>
                    </div>
                </div>

                <div class="footer-links">
                    <h4>Product</h4>
                    <ul>
                        <li><a href="#features">Features</a></li>
                        <li><a href="#services">Services</a></li>
                        <li><a href="#pricing">Pricing</a></li>
                        <li><a href="#how-it-works">How It Works</a></li>
                    </ul>
                </div>

                <div class="footer-links">
                    <h4>Company</h4>
                    <ul>
                        <li><a href="#about-us">About Us</a></li>
                        <li><a href="#trusted-businesses">Partners</a></li>
                        <li><a href="#contact-us">Contact</a></li>
                        <li><a href="#">Careers</a></li>
                    </ul>
                </div>

                <div class="footer-links">
                    <h4>Support</h4>
                    <ul>
                        <li><a href="FAQ.php">FAQ</a></li>
                        <li><a href="../../../Help_center/PHP/Frontend/Help_center.php">Help Center</a></li>
                        <li><a href="terms_of_service.php">Terms of Service</a></li>
                        <li><a href="privacy_policy.php">Privacy Policy</a></li>
                    </ul>
                </div>
            </div>

            <div class="footer-bottom">
                <p>&copy; 2026 ScanQuotient. All rights reserved.</p>

            </div>
        </div>
    </footer>

    <!-- Back to Top -->
    <div class="back-to-top" id="backToTop" onclick="window.scrollTo({top: 0, behavior: 'smooth'})">
        <i class="fas fa-arrow-up"></i>
    </div>

    <!-- Modal -->
    <div class="modal-overlay" id="modalOverlay" onclick="closeModal(event)">
        <div class="modal" onclick="event.stopPropagation()">
            <button class="modal-close" onclick="closeModal()">
                <i class="fas fa-times"></i>
            </button>
            <h2 id="modalTitle" style="font-size: 1.75rem; margin-bottom: 20px; color: var(--text-main);">Service
                Details</h2>
            <div id="modalContent" style="color: var(--text-muted); line-height: 1.8;">
                <p>Detailed service information will appear here.</p>
            </div>
            <button class="btn btn-primary" style="margin-top: 24px;" onclick="closeModal()">
                <i class="fas fa-check"></i> Got It
            </button>
        </div>
    </div>


</body>

</html>