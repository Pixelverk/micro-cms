class SiteHeader extends HTMLElement {
  connectedCallback() {
    if (this._rendered) return;
    this._rendered = true;

    const current = window.location.pathname;

    this.innerHTML = `
      <style>
        header {
          background: #ffffff;
          border-bottom: 1px solid #eaeaea;
        }

        .logo {
          font-size: 1.25rem;
          font-weight: 700;
          color: #222;
          text-decoration: none;
        }

        nav a {
          margin-left: 1.5rem;
          text-decoration: none;
          color: #555;
          font-weight: 500;
        }

        nav a:hover {
          color: #000;
        }
      </style>

      <header>
        <div class="inner">
          <a href="/" class="logo">Acme Consulting</a>
          <nav>
            <a href="/">Home</a>
            <a href="/services/">Services</a>
            <a href="/contact/">Contact</a>
          </nav>
        </div>
      </header>
    `;

    this.querySelectorAll('nav a').forEach(link => {
        if (link.getAttribute('href') === current) {
            link.style.fontWeight = '700';
        }
    });
  }
}

customElements.define('site-header', SiteHeader);
