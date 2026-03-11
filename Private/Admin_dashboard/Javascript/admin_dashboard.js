// Theme Management
const themeToggle = document.getElementById("themeToggle");
function setTheme(theme) {
  document.body.classList.toggle("dark", theme === "dark");
  const icon = theme === "dark" ? "fa-moon" : "fa-sun";
  const text = theme === "dark" ? "Dark Mode" : "Light Mode";
  themeToggle.innerHTML = `<i class="fas ${icon}"></i><span>${text}</span>`;
}
themeToggle.addEventListener("click", () => {
  const current = document.body.classList.contains("dark") ? "light" : "dark";
  setTheme(current);
  localStorage.setItem("theme", current);
});
setTheme(localStorage.getItem("theme") || "light");

// Help Modal
const helpBtn = document.getElementById("helpBtn");
const helpModal = document.getElementById("helpModal");
const closeBtn = document.querySelector(".close");
helpBtn.onclick = () => (helpModal.style.display = "flex");
closeBtn.onclick = () => (helpModal.style.display = "none");
window.onclick = (e) => {
  if (e.target === helpModal) helpModal.style.display = "none";
};

// Data Structure
const categories = [
  {
    title: "Network Intelligence",
    icon: "fa-network-wired",
    items: [
      { label: "Connection Type", id: "connType", icon: "fa-wifi" },
      { label: "IP Address", id: "ipAddress", icon: "fa-globe" },
      { label: "Country", id: "country", icon: "fa-map-marker-alt" },
      { label: "Downlink Speed", id: "downlink", icon: "fa-tachometer-alt" },
    ],
  },
  {
    title: "Browser Security",
    icon: "fa-fingerprint",
    items: [
      { label: "Browser", id: "browserInfo", icon: "fa-chrome" },
      { label: "Operating System", id: "osInfo", icon: "fa-desktop" },
      { label: "Privacy Mode", id: "incognitoStatus", icon: "fa-user-secret" },
      { label: "Cookies Enabled", id: "cookieStatus", icon: "fa-cookie" },
    ],
  },
  {
    title: "Security Protocols",
    icon: "fa-lock",
    items: [
      { label: "HTTPS Status", id: "httpsStatus", icon: "fa-shield-alt" },
      { label: "TLS Version", id: "tlsVersion", icon: "fa-key" },
      { label: "Certificate", id: "certStatus", icon: "fa-certificate" },
      { label: "Ad Blocker", id: "adBlocker", icon: "fa-ban" },
    ],
  },
  {
    title: "System Fingerprint",
    icon: "fa-microchip",
    items: [
      { label: "CPU Cores", id: "cpuCores", icon: "fa-microchip" },
      { label: "Device Memory", id: "deviceMemory", icon: "fa-memory" },
      { label: "Viewport", id: "viewportSize", icon: "fa-window-maximize" },
      { label: "Session Time", id: "sessionTime", icon: "fa-clock" },
    ],
  },
];

let currentCategory = 0;
let autoRotateInterval;

// Initialize
function init() {
  const container = document.getElementById("carouselContainer");

  categories.forEach((cat, idx) => {
    const slide = document.createElement("div");
    slide.className = `carousel-slide ${idx === 0 ? "active" : ""}`;
    slide.innerHTML = `
                    <div class="data-grid">
                        ${cat.items
                          .map(
                            (item) => `
                            <div class="data-item">
                                <div class="data-label"><i class="fas ${item.icon}"></i>${item.label}</div>
                                <div class="data-value" id="${item.id}">--</div>
                            </div>
                        `,
                          )
                          .join("")}
                    </div>
                `;
    container.appendChild(slide);
  });

  startAutoRotate();
  gatherData();
  setInterval(gatherData, 30000);
}

function switchCategory(index) {
  currentCategory = index;

  // Update sidebar buttons
  document.querySelectorAll(".intel-sidebar-btn").forEach((btn, idx) => {
    btn.classList.toggle("active", idx === index);
  });

  // Update title
  const cat = categories[index];
  document.getElementById("intelTitle").innerHTML =
    `<i class="fas ${cat.icon}"></i>${cat.title}`;

  // Update slides
  document.querySelectorAll(".carousel-slide").forEach((slide, idx) => {
    slide.classList.remove("active");
    if (idx === index) slide.classList.add("active");
  });

  resetAutoRotate();
}

function changeSlide(direction) {
  const newIndex =
    (currentCategory + direction + categories.length) % categories.length;
  switchCategory(newIndex);
}

function startAutoRotate() {
  autoRotateInterval = setInterval(() => {
    const newIndex = (currentCategory + 1) % categories.length;
    switchCategory(newIndex);
  }, 6000);
}

function resetAutoRotate() {
  clearInterval(autoRotateInterval);
  startAutoRotate();
}

// Data Gathering
async function gatherData() {
  // Network
  const conn =
    navigator.connection ||
    navigator.mozConnection ||
    navigator.webkitConnection;
  if (conn) {
    document.getElementById("connType").textContent =
      conn.effectiveType || conn.type || "Unknown";
    document.getElementById("downlink").textContent = conn.downlink
      ? `${conn.downlink} Mbps`
      : "--";
  } else {
    document.getElementById("connType").textContent = navigator.onLine
      ? "Online"
      : "Offline";
    document.getElementById("downlink").textContent = "--";
  }

  // IP & Country only
  try {
    const res = await fetch("https://api.ipify.org?format=json", {
      method: "GET",
      headers: {
        Accept: "application/json",
      },
    });

    if (!res.ok) {
      throw new Error("Network response was not ok");
    }

    const data = await res.json();

    document.getElementById("ipAddress").textContent = data.ip || "Unavailable";
    document.getElementById("country").textContent =
      data.country_name || "Unknown";
  } catch (error) {
    console.error("IP lookup failed:", error);

    document.getElementById("ipAddress").textContent = "Unavailable";
    document.getElementById("country").textContent = "Unknown";
  }

  // Browser
  const ua = navigator.userAgent;
  let browser = "Unknown";
  if (ua.includes("Firefox/")) browser = "Firefox";
  else if (ua.includes("Edg/")) browser = "Edge";
  else if (ua.includes("Chrome/")) browser = "Chrome";
  else if (ua.includes("Safari/") && !ua.includes("Chrome")) browser = "Safari";
  document.getElementById("browserInfo").textContent = browser;

  // OS
  let os = "Unknown";
  if (ua.includes("Windows")) os = "Windows";
  else if (ua.includes("Mac")) os = "macOS";
  else if (ua.includes("Linux")) os = "Linux";
  else if (ua.includes("Android")) os = "Android";
  else if (ua.includes("iPhone") || ua.includes("iPad")) os = "iOS";
  document.getElementById("osInfo").textContent = os;

  // Privacy
  const isPrivate = await detectIncognito();
  document.getElementById("incognitoStatus").innerHTML = isPrivate
    ? '<span class="status-warning"><i class="fas fa-eye-slash"></i> Private</span>'
    : '<span class="status-secure"><i class="fas fa-eye"></i> Normal</span>';

  // Cookies
  document.getElementById("cookieStatus").innerHTML = navigator.cookieEnabled
    ? '<span class="status-secure"><i class="fas fa-check"></i> On</span>'
    : '<span class="status-danger"><i class="fas fa-times"></i> Off</span>';

  // HTTPS
  const isHttps = window.location.protocol === "https:";
  document.getElementById("httpsStatus").innerHTML = isHttps
    ? '<span class="status-secure"><i class="fas fa-lock"></i> Secure</span>'
    : '<span class="status-danger"><i class="fas fa-unlock"></i> Insecure</span>';

  // TLS
  let tls = "Unknown";
  if (isHttps) {
    if (performance.getEntriesByType) {
      const entries = performance.getEntriesByType("navigation");
      if (entries[0]?.nextHopProtocol === "h3") tls = "TLS 1.3";
      else if (entries[0]?.nextHopProtocol === "h2") tls = "TLS 1.2";
    }
    if (tls === "Unknown") tls = "TLS 1.2+";
  } else {
    tls = "N/A";
  }
  document.getElementById("tlsVersion").textContent = tls;

  // Certificate
  document.getElementById("certStatus").innerHTML = isHttps
    ? '<span class="status-secure"><i class="fas fa-check-circle"></i> Valid</span>'
    : '<span class="status-warning"><i class="fas fa-exclamation-triangle"></i> None</span>';

  // Ad Blocker
  const adBlock = await detectAdBlocker();
  document.getElementById("adBlocker").innerHTML = adBlock
    ? '<span style="color: var(--brand-color)"><i class="fas fa-shield-alt"></i> Active</span>'
    : "<span>Not Detected</span>";

  // System
  document.getElementById("cpuCores").textContent =
    navigator.hardwareConcurrency || "--";
  document.getElementById("deviceMemory").textContent = navigator.deviceMemory
    ? `${navigator.deviceMemory}GB`
    : "--";
  document.getElementById("viewportSize").textContent =
    `${window.innerWidth}×${window.innerHeight}`;
}

async function detectIncognito() {
  return new Promise((resolve) => {
    if (window.RequestFileSystem || window.webkitRequestFileSystem) {
      const fs = window.RequestFileSystem || window.webkitRequestFileSystem;
      fs(
        window.TEMPORARY,
        100,
        () => resolve(false),
        () => resolve(true),
      );
      return;
    }
    if ("MozAppearance" in document.documentElement.style) {
      const db = indexedDB.open("test");
      db.onerror = () => resolve(true);
      db.onsuccess = () => resolve(false);
      return;
    }
    resolve(false);
  });
}

async function detectAdBlocker() {
  return new Promise((resolve) => {
    const test = document.createElement("div");
    test.className = "adsbox";
    test.style.cssText = "position:absolute;left:-9999px;";
    document.body.appendChild(test);
    setTimeout(() => {
      resolve(test.offsetHeight === 0);
      document.body.removeChild(test);
    }, 100);
  });
}

// Session Timer
let seconds = 0;
setInterval(() => {
  seconds++;
  const h = Math.floor(seconds / 3600)
    .toString()
    .padStart(2, "0");
  const m = Math.floor((seconds % 3600) / 60)
    .toString()
    .padStart(2, "0");
  const s = (seconds % 60).toString().padStart(2, "0");
  const el = document.getElementById("sessionTime");
  if (el) el.textContent = `${h}:${m}:${s}`;
}, 1000);

window.addEventListener("resize", () => {
  const el = document.getElementById("viewportSize");
  if (el) el.textContent = `${window.innerWidth}×${window.innerHeight}`;
});

document.addEventListener("DOMContentLoaded", init);
