"""Build transparent favicon sizes from the orange B icon (no background color changes)."""
from __future__ import annotations

from pathlib import Path

from PIL import Image

ROOT = Path(__file__).resolve().parents[2] / "assets" / "images"
ICON = ROOT / "balitech-icon.png"


def square_transparent_icon(icon: Image.Image, size: int, fill_ratio: float = 0.86) -> Image.Image:
    rgba = icon.convert("RGBA")
    canvas = Image.new("RGBA", (size, size), (0, 0, 0, 0))
    target = int(size * fill_ratio)
    scale = min(target / rgba.width, target / rgba.height)
    new_size = (max(1, int(rgba.width * scale)), max(1, int(rgba.height * scale)))
    resized = rgba.resize(new_size, Image.Resampling.LANCZOS)
    x = (size - new_size[0]) // 2
    y = (size - new_size[1]) // 2
    canvas.paste(resized, (x, y), resized)
    return canvas


def main() -> None:
    icon = Image.open(ICON).convert("RGBA")

    outputs = {
        "balitech-favicon-32.png": 32,
        "balitech-favicon-192.png": 192,
        "balitech-favicon.png": 512,
        "balitech-icon-square.png": 512,
    }
    for name, size in outputs.items():
        out = square_transparent_icon(icon, size)
        out.save(ROOT / name, "PNG", optimize=True)
        alpha = out.split()[-1]
        transparent = sum(1 for a in alpha.getdata() if a < 10)
        print(f"{name}: {size}x{size}, transparent={transparent}/{size*size}")

    print("Transparent favicon assets saved to", ROOT)


if __name__ == "__main__":
    main()
