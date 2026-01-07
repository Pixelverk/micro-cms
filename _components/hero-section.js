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
      </style>

      <section class="hero">
        ${image ? `<img src="./img/${image}" alt="${title}">` : ''}
        <h1>${title}</h1>
        <p>${subtitle}</p>
        <slot></slot>
      </section>
    `;
  }
}

customElements.define('hero-section', HeroSection);
