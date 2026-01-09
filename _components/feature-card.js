class FeatureCard extends HTMLElement {
  connectedCallback() {
    if (this._rendered) return;
    this._rendered = true;

    const title = this.getAttribute('title') || 'Feature Title';
    const text = this.getAttribute('text') || 'Feature description.';
    const icon = this.getAttribute('icon') || '';
    const image = this.getAttribute('image') || '';

    this.innerHTML = `
      <style>
        feature-card {
          display: block;
          background: #fff;
          border-radius: 8px;
          padding: 2rem 1rem;
          box-shadow: 0 2px 6px rgba(0,0,0,0.1);
          text-align: center;
        }

        feature-card img {
          max-width: 100px;
          height: auto;
          margin-bottom: 1rem;
        }

        feature-card h2 {
          font-size: 1.5rem;
          margin-bottom: 0.5rem;
        }

        feature-card p {
          font-size: 1rem;
          color: #555;
        }

        feature-card .icon {
          font-size: 2rem;
          margin-bottom: 1rem;
        }
      </style>

      ${image ? `<img src="/_assets/img/${image}" alt="${title}">` : ''}
      ${icon ? `<div class="icon">${icon}</div>` : ''}
      <h2>${title}</h2>
      <p>${text}</p>
      <slot></slot>
    `;
  }

  static get observedAttributes() {
    return ["title", "text", "icon", "image"];
  }

  attributeChangedCallback() {
    this._rendered = false;
    this.connectedCallback();
  }
}

customElements.define('feature-card', FeatureCard);