class SiteFooter extends HTMLElement {
  connectedCallback() {
    if (this._rendered) return;
    this._rendered = true;

    const year = new Date().getFullYear();

    this.innerHTML = `
      <style>
        footer {
          background: #111;
          color: #ccc;
          margin-top: 4rem;
        }

        .top {
          display: flex;
          justify-content: space-between;
          flex-wrap: wrap;
          gap: 1rem;
          margin: 1rem 0;
        }

        .brand {
          font-weight: 600;
          color: #fff;
        }

        footer nav a {
          margin-right: 1.25rem;
          text-decoration: none;
          color: #ccc;
          font-size: 0.95rem;
        }

        footer nav a:hover {
          color: #fff;
        }

        .bottom {
          font-size: 0.85rem;
          color: #888;
          border-top: 1px solid #222;
          padding-top: 1rem;
        }

        footer .inner {
          display:block;
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