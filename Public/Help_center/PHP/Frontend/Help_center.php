<?php
session_start();
require_once __DIR__ . '/../../../security_headers.php';
$toast_message = $_SESSION['toast_message'] ?? ''; // Renamed for clarity
unset($_SESSION['toast_message']);

// Determine initial theme based on cookie. Default to 'dark' if no cookie is set.
$initial_theme_class = 'dark-theme';
if (isset($_COOKIE['theme'])) {
    if ($_COOKIE['theme'] === 'light') {
        $initial_theme_class = 'light-theme';
    } else {
        $initial_theme_class = 'dark-theme'; // Ensure it's explicitly 'dark-theme' if cookie is 'dark'
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>ScanQuotient | Help Center</title>
    <link rel="icon" type="image/png" href="../../../../Storage/Public_images/page_icon.png" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"
        integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA=="
        crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link rel="stylesheet" type="text/css" href="../../CSS/ticket_page_site.css">

</head>

<body class="<?= $initial_theme_class ?>">
    <div id="toast-container"></div>

    <script>
        window.toast_message_data = <?= json_encode($toast_message) ?>;
    </script>

    <header class="site-header">
        <div class="header-container">
            <div class="logo-group">
                <div class="logo-link">
                    <h1 class="logo">ScanQuotient</h1>
                    <p class="tagline">Quantifying Risk.Strengthening Security</p>
                </div>
            </div>
            <div class="header-buttons">
                <div class="theme-switch-wrapper">
                    <button class="theme-icon-btn" id="theme-toggle" aria-label="Toggle Theme">
                        <i class="fas fa-moon" id="theme-icon"></i>
                    </button>
                </div>

                <a href="../../../Homepage/PHP/Frontend/Homepage.php">
                    <button class="header-button" aria-label="Home Page" data-tooltip="Home">
                        <i class="fa fa-home"></i>
                    </button>
                </a>
            </div>
        </div>
    </header>

    <main class="main-content">
        <div class="left-section" id="left-section">
            <div class="collapsible-menu" id="collapsible-menu">
                <div class="nav-header">
                    <span class="nav-label">Navigation</span>
                    <button class="collapsible-button" id="menu-toggle">
                        <i class="fa fa-chevron-left"></i>
                    </button>
                </div><br>
                <div class="collapsible-content" id="collapsible-content">
                    <div class="like-boxes" id="like-boxes">
                        <div class="like-box" id="home-button"><i class="fa fa-home"></i> <span>Home</span></div>
                        <div class="like-box" id="create-ticket-box"><i class="fa fa-plus-circle"></i> <span>Create New
                                Ticket</span></div>
                        <div class="like-box" id="view-prev-tickets-box"><i class="fa fa-history"></i> <span>View
                                Previous Tickets</span></div>
                        <div class="like-box" id="track-ticket-box"><i class="fa fa-search"></i> <span>Track Ticket
                                Progress</span></div>

                        <a href="../../../Homepage/PHP/Frontend/FAQ.php" style="text-decoration: none; color: inherit;">
                            <div class="like-box" id="faq-box">
                                <i class="fa fa-question-circle"></i>
                                <span>Frequently Asked Questions</span>
                            </div>
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <div class="vertical-divider" id="vertical-divider"></div>

        <div class="right-section" id="right-section">
            <div class="welcome-message" id="welcome-message">
                <p>Welcome to our support center! For assistance, please click <a href="#" id="create-ticket-link"
                        style="color: var(--brand-color); cursor: pointer;">Create New Ticket</a> to submit your
                    request.</p>
            </div>
            <div class="notifications-container" id="notifications-container">
                <div class="notifications-header">
                    <i class="fa fa-bell"></i> Notifications
                </div>
                <p><i class="fa fa-bell-slash"></i> No notifications currently.</p>
            </div>
            <form id="ticket-form" action="../Backend/ticket_page_submission.php" method="post"
                enctype="multipart/form-data">
                <div class="ticket-form-container" id="ticket-form-container">
                    <section class="form-section">
                        <h2>Create New Support Ticket</h2>

                        <div class="form-group">
                            <label for="name">Full Name *</label>
                            <input type="text" id="name" name="name" required placeholder="Your full name" />
                        </div>

                        <div class="form-group">
                            <label for="email">Email Address *</label>
                            <input type="email" id="email" name="email" required placeholder="you@example.com" />
                        </div>

                        <div class="form-group">
                            <label for="phone">Phone Number *</label>
                            <input type="tel" id="phone" name="phone" required placeholder="Your phone number" />
                        </div>

                        <div class="form-group">
                            <label for="category">Issue Category *</label>
                            <select id="category" name="category" required>
                                <option value="">-- Select an issue category --</option>
                                <option value="account">Account & Registration</option>
                                <option value="platform">Platform Features & Usage</option>
                                <option value="login">Login Issues</option>
                                <option value="technical">Technical Problem</option>
                                <option value="other">Other</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="priority">Priority *</label>
                            <select id="priority" name="priority" required>
                                <option value="">-- Select priority --</option>
                                <option value="low">Low</option>
                                <option value="medium">Medium</option>
                                <option value="high">High</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="subject">Subject *</label>
                            <input type="text" id="subject" name="subject" required
                                placeholder="Briefly describe your issue" />
                        </div>

                        <div class="form-group">
                            <label for="message">Detailed Description *</label>
                            <textarea id="message" name="message" rows="7" required
                                placeholder="Please provide as much detail as possible about your problem."></textarea>
                        </div>

                        <div class="form-group">
                            <label for="attachment">Attachments (optional)</label>
                            <input type="file" id="attachment" name="attachments[]" accept=".jpg,.jpeg,.png,.pdf,.txt"
                                multiple />

                            <small style="color: #999;">
                                You can select multiple files. Supported: JPG, JPEG, PNG, PDF, TXT
                            </small>

                            <ul id="file-list" style="margin-top: 10px; list-style: none; padding: 0;"></ul>
                        </div>

                        <button type="submit" class="btn-primary">
                            Submit Support Ticket
                        </button>
                    </section>
                </div>
            </form>

            <div class="track-ticket-id-container" id="view-previous-tickets-container" style="display: none;">
                <section class="form-section">
                    <h2>Your Submitted Tickets</h2>
                    <p class="info-message">To view the status and details of your previously submitted tickets, please
                        enter your Ticket ID below. This ID was sent to your email after submission.</p>
                    <div class="form-group">
                        <label for="view-ticket-id-input">Ticket ID *</label>
                        <input type="text" id="view-ticket-id-input" name="ticket_id_view" placeholder="e.g., TABC123DE"
                            required />
                    </div>
                    <button type="button" class="btn-primary" id="view-ticket-button">View Ticket</button>
                </section>
            </div>
            <div class="track-ticket-id-container" id="track-ticket-progress-container" style="display: none;">
                <section class="form-section">
                    <h2>Track Individual Ticket Progress</h2>
                    <p class="info-message">Enter a specific Ticket ID here to get the latest progress updates and
                        direct messages related to that ticket. This is useful for detailed status checks.</p>
                    <div class="form-group">
                        <label for="track-ticket-id-input">Ticket ID *</label>
                        <input type="text" id="track-ticket-id-input" name="ticket_id_track"
                            placeholder="e.g., TABC123DE" required />
                    </div>
                    <button type="button" class="btn-primary" id="track-ticket-progress-button">Track Progress</button>
                </section>
            </div>
            <div class="urgent-assistance" id="urgent-assistance">
                <h3>Need More Immediate Assistance?</h3>
                <p>If your issue is urgent, please consider the following options:</p>
                <ul>

                    <li><strong>Phone Support:</strong> Call us at <a href="tel:+254115520624"
                            style="color: var(--brand-color);">+254 115 520 624</a> (Monday - Friday, 9 AM - 5 PM EAT).
                    </li>
                    <li><strong>WhatsApp:</strong> <a href="https://wa.me/254115520624"
                            style="color: var(--brand-color);">Chat with us on WhatsApp</a>.</li>
                    <li>
                        <strong>Email:</strong>
                        <a href="mailto:scanquotient@gmail.com"
                            style="color: var(--brand-color);">scanquotient@gmail.com</a>
                    </li>
                </ul>
            </div>
        </div>
    </main>

    <footer class="site-footer">
        <div class="footer-left">
            <div class="logo-link">
                <h1 class="footer-logo">ScanQuotient</h1>
                <p class="footer-tagline">Quantifying Risk.Strengthening Security</p>
            </div>
        </div>
        <div class="footer-center">
            <p>© 2026 ScanQuotient.All rights reserved.</p>
        </div>
        <div class="footer-right">
            <p>Contact us at: <a href="mailto:scanquotient@gmail.com">scanquotient@gmail.com</a></p>
        </div>
    </footer>
    <button type="button" class="back-to-top" aria-label="Back to Top">
        <i class="fas fa-arrow-up"></i>
    </button>
    <script>
        const select = document.getElementById('priority');

        select.addEventListener('change', function () {
            switch (this.value) {
                case 'low':
                    this.style.backgroundColor = '#d4edda';
                    this.style.color = '#155724';
                    break;
                case 'medium':
                    this.style.backgroundColor = '#fff3cd';
                    this.style.color = '#856404';
                    break;
                case 'high':
                    this.style.backgroundColor = '#f8d7da';
                    this.style.color = '#721c24';
                    break;
                default:
                    this.style.backgroundColor = '#ffffff';
                    this.style.color = '#000000';
            }
        });
    </script>

    <script src="../../Javascript/ticket_page_site.js"></script>
</body>

</html>