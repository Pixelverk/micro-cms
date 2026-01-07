class SiteFooter extends HTMLElement {
    constructor() {
        super();
        this.attachShadow({ mode: 'open' });
    }

  connectedCallback() {
    if (this._rendered) return;
    this._rendered = true;

    const year = new Date().getFullYear();

    this.shadowRoot.innerHTML = `
      <style>
        footer {
          background: #111;
          color: #ccc;
          margin-top: 4rem;
        }

        .inner {
          max-width: 1100px;
          margin: 0 auto;
          padding: 2rem;
          display: flex;
          flex-direction: column;
          gap: 1rem;
        }

        .top {
          display: flex;
          justify-content: space-between;
          flex-wrap: wrap;
          gap: 1rem;
        }

        .brand {
          font-weight: 600;
          color: #fff;
        }

        nav a {
          margin-right: 1.25rem;
          text-decoration: none;
          color: #ccc;
          font-size: 0.95rem;
        }

        nav a:hover {
          color: #fff;
        }

        .bottom {
          font-size: 0.85rem;
          color: #888;
          border-top: 1px solid #222;
          padding-top: 1rem;
        }
      </style>

      <footer>
        <div class="inner">
          <div class="top">
            <div class="brand">Acme Consulting</div>
            <nav>
              <a href="/">Home</a>
              <a href="/services/">Services</a>
              <a href="/contact/">Contact</a>
            </nav>
          </div>

          <div class="bottom">
            © ${year} Acme Consulting. All rights reserved.
          </div>
        </div>
      </footer>
    `;
  }
}

customElements.define('site-footer', SiteFooter);