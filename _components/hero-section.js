class HeroSection extends HTMLElement {
  constructor() {
    super();
    this.attachShadow({ mode: 'open' });
  }

  connectedCallback() {
    if (this._rendered) return;
    this._rendered = true;

    const title = this.getAttribute('title') || 'Default Title';
    const subtitle = this.getAttribute('subtitle') || 'Default subtitle';
    const image = this.getAttribute('image') || '';

    this.shadowRoot.innerHTML = `
      <style>
        .hero {
          position: relative;
          text-align: center;
          color: #fff;
          padding: 4rem 2rem;
          background: #333;          
        }

        .hero img {
          max-width: 100%;
          height: auto;
          margin-bottom: 2rem;
          display: block;
          margin-left: auto;
          margin-right: auto;
        }

        .hero h1 {
          font-size: 2.5rem;
          margin-bottom: 1rem;
        }

        .hero p {
          font-size: 1.25rem;
          color: #ddd;
        }

        .inner {
          max-width: 1100px;
          margin: 0 auto;
          padding: 1rem 2rem;
          display: flex;
          align-items: center;
          justify-content: space-between;
          gap: 4rem;
        }
      </style>

      <section class="hero">
        <div class="inner">
        <div class="hero-text">
          <h1>${title}</h1>
          <p>${subtitle}</p>
        </div>
        <div class="hero-img">
          ${image ? `<img src="/_assets/img/${image}" alt="${title}">` : ''}
        </div>
        <slot></slot>
        </div>
      </section>
    `;
  }
  static get observedAttributes() {
    return ["title","subtitle","image"];
  }

}

customElements.define('hero-section', HeroSection);
