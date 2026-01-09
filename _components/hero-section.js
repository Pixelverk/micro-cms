class HeroSection extends HTMLElement {
  connectedCallback() {
    if (this._rendered) return;
    this._rendered = true;

    const title = this.getAttribute('title') || 'Default Title';
    const subtitle = this.getAttribute('subtitle') || 'Default subtitle';
    const image = this.getAttribute('image') || '';

    this.innerHTML = `
      <style>
        hero-section {
          display: block;
          background: #333;
        }

        hero-section .hero {
          position: relative;
          text-align: center;
          color: #fff;
          padding: 4rem 2rem;
        }

        hero-section .hero img {
          max-width: 100%;
          height: auto;
          margin-bottom: 2rem;
          display: block;
          margin-left: auto;
          margin-right: auto;
        }

        hero-section .hero h1 {
          font-size: 2.5rem;
          margin-bottom: 1rem;
        }

        hero-section .hero p {
          font-size: 1.25rem;
          color: #ddd;
        }

        hero-section .inner {
          gap: 4rem;
        }
      </style>

      <div class="hero inner">
          <div class="hero-text">
            <h1>${title}</h1>
            <p>${subtitle}</p>
          </div>

          <div class="hero-img">
            ${image ? `<img src="/_assets/img/${image}" alt="${title}">` : ''}
          </div>

          <slot></slot>
      </div>
    `;
  }

  static get observedAttributes() {
    return ["title", "subtitle", "image"];
  }

  attributeChangedCallback() {
    this._rendered = false;
    this.connectedCallback();
  }
}

customElements.define('hero-section', HeroSection);