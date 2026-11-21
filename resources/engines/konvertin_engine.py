"""Mesin pengolahan PDF untuk Konvertin.

Dipanggil oleh App\\Conversion\\Converters\\PythonConverter:

    python konvertin_engine.py <operasi> --out-dir DIR --name NAMA [--aux KUNCI=PATH ...] BERKAS...

Opsi operasi dikirim sebagai JSON lewat stdin, sehingga data seperti kata
sandi tidak pernah muncul di daftar proses sistem operasi.

Keluaran berhasil: baris terakhir stdout berisi JSON
{"file": "<path hasil>", "download": "<nama unduhan>", "report": {...}}.
Kode keluar dipetakan ke App\\Conversion\\ConversionFailure.
"""

import argparse
import base64
import difflib
import io
import json
import logging
import os
import re
import secrets
import shutil
import sys
import zipfile

EXIT_GENERIC = 2
EXIT_NO_TABLES = 3
EXIT_ENCRYPTED = 4
EXIT_NO_TEXT = 5
EXIT_NOTHING_TO_OCR = 6
EXIT_WRONG_PASSWORD = 7
EXIT_NOT_PROTECTED = 8
EXIT_INVALID_PAGES = 9
EXIT_UNRECOVERABLE = 10
EXIT_TOO_MANY_PAGES = 11
EXIT_NO_FIELDS = 12
EXIT_NOTHING_FOUND = 13
EXIT_EXPORT_TOO_LARGE = 14

A4 = (595.0, 842.0)
PAGE_MARGIN = 24.0
JPG_DPI = 150
OCR_DPI = 200

logging.disable(logging.CRITICAL)


class EngineError(Exception):
    def __init__(self, code, message=""):
        super().__init__(message)
        self.code = code


class Context:
    def __init__(self, args, options):
        self.args = args
        self.options = options
        self.out_dir = args.out_dir
        self.name = args.name
        self.inputs = args.inputs
        self.aux = dict(item.split("=", 1) for item in (args.aux or []))
        self.max_pages = int(options.get("_max_pages") or 0)

    def opt(self, key, default=None):
        value = self.options.get(key)
        return default if value is None or value == "" else value

    def out(self, filename):
        return os.path.join(self.out_dir, filename)


def done(path, download=None, report=None):
    print(json.dumps({"file": path, "download": download, "report": report or {}}))


# Kemajuan proses untuk antarmuka: satu baris JSON di stderr per langkah. Hanya
# angka langkah dan keterangan umum, tanpa isi dokumen. Rentang bersarang
# (workflow, kompresi di dalam paket) memetakan kemajuan anak ke bagian induknya.
_PROGRESS_SCOPE = [(0.0, 1.0)]


def progress(done_steps, total_steps, label):
    start, end = _PROGRESS_SCOPE[-1]
    fraction = None
    if total_steps:
        fraction = start + (end - start) * max(0.0, min(1.0, float(done_steps) / float(total_steps)))
    sys.stderr.write("@@progress " + json.dumps({"fraction": fraction, "label": label}) + "\n")
    sys.stderr.flush()


class progress_scope:
    """Menjalankan sebagian pekerjaan pada potongan kemajuan [a, b] dari rentang saat ini."""

    def __init__(self, a, b):
        start, end = _PROGRESS_SCOPE[-1]
        self.range = (start + (end - start) * a, start + (end - start) * b)

    def __enter__(self):
        _PROGRESS_SCOPE.append(self.range)

    def __exit__(self, *exc):
        _PROGRESS_SCOPE.pop()


# ------------------------------------------------------------------ helpers


def open_pdf(path, ctx=None, allow_encrypted=False):
    import pymupdf

    try:
        doc = pymupdf.open(path, filetype="pdf")
    except Exception as error:
        raise EngineError(EXIT_GENERIC, "PDF tidak dapat dibaca: %s" % error)

    if not allow_encrypted and (doc.needs_pass or doc.is_encrypted):
        raise EngineError(EXIT_ENCRYPTED, "PDF diproteksi kata sandi")

    if doc.page_count == 0:
        raise EngineError(EXIT_GENERIC, "PDF tidak memiliki halaman")

    if ctx is not None and ctx.max_pages and doc.page_count > ctx.max_pages:
        raise EngineError(EXIT_TOO_MANY_PAGES, "Terlalu banyak halaman")

    return doc


def save_pdf(doc, target, **extra):
    options = {"garbage": 3, "deflate": True}
    options.update(extra)
    doc.save(target, **options)
    return target


def parse_pages(spec, total):
    """Mengubah "1-3,5" menjadi indeks berbasis nol. Galat bila di luar jangkauan."""
    if spec is None or str(spec).strip() == "":
        return list(range(total))

    pages = []
    for part in str(spec).replace(" ", "").split(","):
        if part == "":
            continue
        match = re.fullmatch(r"(\d+)(?:-(\d+))?", part)
        if not match:
            raise EngineError(EXIT_INVALID_PAGES, "Format halaman tidak valid")
        start = int(match.group(1))
        end = int(match.group(2) or start)
        if start < 1 or end < start or end > total:
            raise EngineError(EXIT_INVALID_PAGES, "Halaman di luar jangkauan")
        pages.extend(range(start - 1, end))

    if not pages:
        raise EngineError(EXIT_INVALID_PAGES, "Tidak ada halaman dipilih")

    return pages


def parse_groups(spec, total):
    """Mengubah "1-3,4-6" menjadi daftar kelompok halaman."""
    groups = []
    for part in str(spec or "").replace(" ", "").split(","):
        if part:
            groups.append(parse_pages(part, total))
    if not groups:
        raise EngineError(EXIT_INVALID_PAGES, "Tidak ada rentang halaman")
    return groups


def scoped_pages(ctx, total):
    if ctx.opt("scope", "all") == "all":
        return list(range(total))
    return sorted(set(parse_pages(ctx.opt("pages"), total)))


def hex_color(value, default=(0, 0, 0)):
    value = str(value or "").lstrip("#")
    if not re.fullmatch(r"[0-9a-fA-F]{6}", value):
        return default
    return tuple(int(value[i:i + 2], 16) / 255 for i in (0, 2, 4))


def visual_rect(page, box):
    """Kotak ternormalisasi (0..1, tampilan halaman) ke koordinat PDF halaman."""
    import pymupdf

    width, height = page.rect.width, page.rect.height
    rect = pymupdf.Rect(box[0] * width, box[1] * height, box[2] * width, box[3] * height)
    rect.normalize()
    return rect * page.derotation_matrix


def visual_point(page, point):
    import pymupdf

    return pymupdf.Point(point[0] * page.rect.width, point[1] * page.rect.height) * page.derotation_matrix


def to_visual_box(page, rect):
    """Koordinat PDF ke kotak ternormalisasi tampilan halaman."""
    visual = rect * page.rotation_matrix
    width, height = page.rect.width or 1, page.rect.height or 1
    return [
        round(max(0, visual.x0 / width), 5),
        round(max(0, visual.y0 / height), 5),
        round(min(1, visual.x1 / width), 5),
        round(min(1, visual.y1 / height), 5),
    ]


def decode_data_url(value):
    if not isinstance(value, str) or "," not in value:
        raise EngineError(EXIT_GENERIC, "Gambar tidak valid")
    header, data = value.split(",", 1)
    if not header.startswith("data:image/"):
        raise EngineError(EXIT_GENERIC, "Gambar tidak valid")
    return base64.b64decode(data)


def zip_outputs(target, files):
    with zipfile.ZipFile(target, "w", compression=zipfile.ZIP_DEFLATED) as bundle:
        for path, name in files:
            bundle.write(path, arcname=name)
            os.remove(path)
    return target


PAPER_SIZES = {
    "a4": (595.28, 841.89),
    "letter": (612.0, 792.0),
    "legal": (612.0, 1008.0),
    "f4": (609.45, 935.43),
    "a5": (419.53, 595.28),
}
MM = 72 / 25.4
IMAGE_MARGINS = {"none": 0.0, "small": PAGE_MARGIN, "large": PAGE_MARGIN * 2}


def oriented(size, orientation, landscape_source):
    width, height = size
    landscape = landscape_source if orientation not in ("portrait", "landscape") else orientation == "landscape"
    return (max(width, height), min(width, height)) if landscape else (min(width, height), max(width, height))


def place_image(out, data, width, height, paper="a4", orientation="auto", margin=PAGE_MARGIN, fit="fit"):
    """Satu gambar per halaman, utuh tanpa terpotong atau meregang (4.2/REQ-13)."""
    import pymupdf

    natural_width, natural_height = width * 0.75, height * 0.75  # piksel pada 96 dpi ke poin
    if paper == "image":
        page_width, page_height = natural_width + 2 * margin, natural_height + 2 * margin
    else:
        page_width, page_height = oriented(PAPER_SIZES.get(paper, PAPER_SIZES["a4"]), orientation, width > height)
    page = out.new_page(width=page_width, height=page_height)
    area = pymupdf.Rect(margin, margin, page_width - margin, page_height - margin)
    if fit == "actual" and natural_width <= area.width and natural_height <= area.height:
        center = pymupdf.Point(area.x0 + area.width / 2, area.y0 + area.height / 2)
        area = pymupdf.Rect(center.x - natural_width / 2, center.y - natural_height / 2, center.x + natural_width / 2, center.y + natural_height / 2)
    page.insert_image(area, stream=data, keep_proportion=True)
    return page


def fit_image_page(out, data, width, height):
    return place_image(out, data, width, height)


def ensure_text(doc):
    for page in doc:
        if page.get_text("text").strip():
            return
    raise EngineError(EXIT_NO_TEXT, "PDF tidak memiliki lapisan teks")


# --------------------------------------------------------------- konversi


def op_check(ctx):
    import pymupdf  # noqa: F401
    import pdf2docx  # noqa: F401
    import pptx  # noqa: F401
    import openpyxl  # noqa: F401
    import pymupdf4llm  # noqa: F401
    from PIL import Image  # noqa: F401

    print(json.dumps({"ok": True}))


def op_check_ocr(ctx):
    import cv2  # noqa: F401
    import numpy  # noqa: F401
    from rapidocr_onnxruntime import RapidOCR  # noqa: F401

    print(json.dumps({"ok": True}))


def image_bytes_upright(path):
    """Membaca gambar dan menerapkan orientasi EXIF (foto ponsel)."""
    from PIL import Image, ImageOps

    try:
        with Image.open(path) as image:
            upright = ImageOps.exif_transpose(image)
            width, height = upright.size
            if image.format == "PNG":
                if upright.mode in ("RGBA", "LA") or (upright.mode == "P" and "transparency" in upright.info):
                    rgba = upright.convert("RGBA")
                    flat = Image.new("RGB", rgba.size, (255, 255, 255))
                    flat.paste(rgba, mask=rgba.getchannel("A"))
                    upright = flat
                buffer = io.BytesIO()
                upright.convert("RGB" if upright.mode not in ("RGB", "L") else upright.mode).save(buffer, format="PNG", optimize=True)
                return buffer.getvalue(), width, height
            if upright is not image or image.mode not in ("RGB", "L") or image.format != "JPEG":
                buffer = io.BytesIO()
                upright.convert("RGB").save(buffer, format="JPEG", quality=92)
                return buffer.getvalue(), width, height
            with open(path, "rb") as handle:
                return handle.read(), width, height
    except Exception as error:
        raise EngineError(EXIT_GENERIC, "Gambar tidak dapat dibaca: %s" % error)


def op_jpg2pdf(ctx):
    import pymupdf

    paper = ctx.opt("page_size", "a4")
    orientation = ctx.opt("orientation", "auto")
    margin = IMAGE_MARGINS.get(ctx.opt("margin", "small"), PAGE_MARGIN)
    fit = ctx.opt("fit", "fit")

    if ctx.opt("merge", "one") == "separate" and len(ctx.inputs) > 1:
        files = []
        for number, path in enumerate(ctx.inputs, start=1):
            progress(number - 1, len(ctx.inputs), "Menempatkan foto %d dari %d" % (number, len(ctx.inputs)))
            data, width, height = image_bytes_upright(path)
            single = pymupdf.open()
            place_image(single, data, width, height, paper, orientation, margin, fit)
            name = "%s-%02d.pdf" % (ctx.name, number)
            files.append((save_pdf(single, ctx.out(name)), name))
        done(zip_outputs(ctx.out("result.zip"), files), ctx.name + ".zip", {"files": len(files)})
        return

    out = pymupdf.open()
    for number, path in enumerate(ctx.inputs, start=1):
        progress(number - 1, len(ctx.inputs), "Menempatkan foto %d dari %d" % (number, len(ctx.inputs)))
        data, width, height = image_bytes_upright(path)
        place_image(out, data, width, height, paper, orientation, margin, fit)
    progress(1, 1, "Menyimpan PDF")

    done(save_pdf(out, ctx.out("result.pdf")), ctx.name + ".pdf", {"pages": out.page_count})


EXPORT_DPI = (72, 150, 300, 600)
EXPORT_PIXEL_BUDGET = 5_000_000_000


def op_pdf2jpg(ctx):
    doc = open_pdf(ctx.inputs[0], ctx)
    digits = max(3, len(str(doc.page_count)))
    image_format = "png" if ctx.opt("format", "jpg") == "png" else "jpg"
    dpi = int(ctx.opt("dpi", JPG_DPI))
    if dpi not in EXPORT_DPI:
        raise EngineError(EXIT_GENERIC, "DPI tidak didukung")
    quality = max(50, min(100, int(ctx.opt("quality", 90))))
    selected = scoped_pages(ctx, doc.page_count)

    # Batas jumlah piksel agar ekspor 300/600 DPI tidak menghabiskan disk dan waktu proses.
    pixels = sum((doc[index].rect.width * dpi / 72) * (doc[index].rect.height * dpi / 72) for index in selected)
    if pixels > EXPORT_PIXEL_BUDGET:
        raise EngineError(EXIT_EXPORT_TOO_LARGE, "Ekspor terlalu besar")

    images = []
    for position, index in enumerate(selected, start=1):
        number = index + 1
        progress(position - 1, len(selected), "Mengubah halaman %d menjadi gambar (%d dari %d)" % (number, position, len(selected)))
        pixmap = doc[index].get_pixmap(dpi=dpi, alpha=False)
        name = "%s-hal-%s.%s" % (ctx.name, str(number).zfill(digits), image_format)
        target = ctx.out("page-%s.%s" % (str(number).zfill(digits), image_format))
        if image_format == "png":
            pixmap.save(target)
        else:
            pixmap.save(target, jpg_quality=quality)
        images.append({"path": target, "name": name, "page": number, "width": pixmap.width, "height": pixmap.height, "size": os.path.getsize(target)})
        pixmap = None

    report = {
        "files": len(images),
        "format": image_format,
        "dpi": dpi,
        "quality": quality if image_format == "jpg" else None,
        "total_size": sum(image["size"] for image in images),
        "images": [{key: image[key] for key in ("name", "page", "width", "height", "size")} for image in images[:600]],
    }

    if len(images) == 1:
        done(images[0]["path"], ctx.name + "." + image_format, report)
        return

    archive = ctx.out("result.zip")
    with zipfile.ZipFile(archive, "w", compression=zipfile.ZIP_STORED) as bundle:
        for image in images:
            bundle.write(image["path"], arcname=image["name"])
            os.remove(image["path"])

    done(archive, "%s-%s.zip" % (ctx.name, image_format), report)


def op_pdf2docx(ctx):
    from pdf2docx import Converter

    doc = open_pdf(ctx.inputs[0], ctx)
    target = ctx.out("result.docx")
    source, ocr_pages = ctx.inputs[0], 0
    if truthy(ctx.opt("ocr_first")):
        with progress_scope(0, 0.5):
            source, ocr_pages = ocr_first(ctx, doc, editable=True)
        doc = open_pdf(source, ctx)

    converter = Converter(source)
    try:
        converter.convert(target)
    finally:
        converter.close()

    if not os.path.isfile(target) or os.path.getsize(target) == 0:
        raise EngineError(EXIT_GENERIC, "Dokumen Word tidak terbentuk")

    quality = safe_inspection("docx", doc, target)
    if quality and ocr_pages:
        quality["checks"].insert(0, check("OCR", "info", "%d halaman scan diubah menjadi teks lewat OCR" % ocr_pages, "Teks hasil OCR bisa disunting di Word, tetapi gambar halaman scan tersebut tidak ikut. Periksa ejaan hasil pengenalan."))
    done(target, ctx.name + ".docx", {"quality": quality, "ocr_pages": ocr_pages})


def op_pdf2pptx(ctx):
    from pptx import Presentation
    from pptx.util import Emu

    doc = open_pdf(ctx.inputs[0], ctx)
    points_to_emu = 12700

    first = doc[0].rect
    presentation = Presentation()
    presentation.slide_width = Emu(int(first.width * points_to_emu))
    presentation.slide_height = Emu(int(first.height * points_to_emu))
    blank_layout = presentation.slide_layouts[6]
    slide_width = presentation.slide_width
    slide_height = presentation.slide_height

    for page in doc:
        progress(page.number, doc.page_count, "Menyusun slide %d dari %d" % (page.number + 1, doc.page_count))
        slide = presentation.slides.add_slide(blank_layout)
        image = io.BytesIO(page.get_pixmap(dpi=JPG_DPI).tobytes("png"))
        scale = min(slide_width / (page.rect.width * points_to_emu), slide_height / (page.rect.height * points_to_emu))
        width = int(page.rect.width * points_to_emu * scale)
        height = int(page.rect.height * points_to_emu * scale)
        slide.shapes.add_picture(image, (slide_width - width) // 2, (slide_height - height) // 2, width, height)

        text = page.get_text("text").strip()
        if text:
            slide.notes_slide.notes_text_frame.text = text

    target = ctx.out("result.pptx")
    presentation.save(target)
    done(target, ctx.name + ".pptx", {"quality": safe_inspection("pptx", doc, target)})


NUMBER_PATTERN = re.compile(r"^-?(0|[1-9]\d*)(\.\d+)?$")


def cell_value(raw):
    if raw is None:
        return None
    value = str(raw).strip()
    # Hanya angka polos yang diubah menjadi angka; nilai seperti NIM berawalan 0 tetap teks.
    if NUMBER_PATTERN.match(value):
        return float(value) if "." in value else int(value)
    return value


def op_pdf2xlsx(ctx):
    from openpyxl import Workbook
    from openpyxl.styles import Font
    from openpyxl.utils import get_column_letter

    doc = open_pdf(ctx.inputs[0], ctx)
    workbook = Workbook()
    workbook.remove(workbook.active)
    table_count = 0

    for page_number, page in enumerate(doc, start=1):
        progress(page_number - 1, doc.page_count, "Mencari tabel di halaman %d dari %d" % (page_number, doc.page_count))
        try:
            tables = page.find_tables().tables
        except Exception:
            tables = []

        for table_number, table in enumerate(tables, start=1):
            rows = [row for row in table.extract() if any(cell not in (None, "") for cell in row)]
            if not rows:
                continue

            table_count += 1
            sheet = workbook.create_sheet(title=("Hal %d Tabel %d" % (page_number, table_number))[:31])

            for row in rows:
                sheet.append([cell_value(cell) for cell in row])

            for cell in sheet[1]:
                cell.font = Font(bold=True)

            for index, column in enumerate(sheet.iter_cols(values_only=True), start=1):
                longest = max((len(str(value)) for value in column if value is not None), default=8)
                sheet.column_dimensions[get_column_letter(index)].width = min(max(longest + 2, 8), 60)

    if table_count == 0:
        raise EngineError(EXIT_NO_TABLES, "Tidak ada tabel")

    target = ctx.out("result.xlsx")
    workbook.save(target)
    done(target, ctx.name + ".xlsx", {"tables": table_count, "quality": safe_inspection("xlsx", doc, target)})


def simple_markdown(doc):
    """Cadangan terakhir: teks per blok, judul dikenali dari ukuran huruf yang lebih besar."""
    sizes = []
    for page in doc:
        for block in page.get_text("dict").get("blocks", []):
            for line in block.get("lines", []):
                for span in line.get("spans", []):
                    if span.get("text", "").strip():
                        sizes.append(round(span.get("size", 0), 1))
    body = sorted(sizes)[len(sizes) // 2] if sizes else 0

    parts = []
    for page in doc:
        for block in page.get_text("dict").get("blocks", []):
            lines = []
            largest = 0
            for line in block.get("lines", []):
                text = "".join(span.get("text", "") for span in line.get("spans", [])).strip()
                if text:
                    lines.append(text)
                    largest = max([largest] + [span.get("size", 0) for span in line.get("spans", [])])
            if not lines:
                continue
            text = " ".join(lines)
            if body and largest >= body * 1.5 and len(text) < 120:
                parts.append("## " + text)
            elif body and largest >= body * 1.2 and len(text) < 120:
                parts.append("### " + text)
            else:
                parts.append(text)
        parts.append("")
    return "\n\n".join(part for part in parts if part).strip() + "\n"


def op_pdf2md(ctx):
    import pymupdf4llm

    doc = open_pdf(ctx.inputs[0], ctx)
    ocr_pages = 0
    if truthy(ctx.opt("ocr_first")):
        with progress_scope(0, 0.6):
            source, ocr_pages = ocr_first(ctx, doc)
        doc = open_pdf(source, ctx)
    ensure_text(doc)

    # Bertingkat: pustaka dengan analisis tata letak, lalu mode klasiknya, lalu
    # ekstraksi sederhana. Sebagian PDF membuat analisis tata letak gagal, tetapi
    # teksnya tetap dapat diambil.
    attempts = [
        ("layout", lambda: pymupdf4llm.to_markdown(doc, show_progress=False)),
        ("classic", lambda: pymupdf4llm.helpers.pymupdf_rag.to_markdown(doc, show_progress=False)),
        ("simple", lambda: simple_markdown(doc)),
    ]
    markdown = ""
    method = None
    problems = []
    for name, attempt in attempts:
        try:
            markdown = attempt() or ""
        except Exception as error:  # noqa: BLE001
            problems.append("%s:%s" % (name, type(error).__name__))
            continue
        if markdown.strip():
            method = name
            break

    if problems:
        # Nama tahap dan jenis galat saja, tanpa isi dokumen.
        sys.stderr.write("pdf2md fallback %s\n" % ",".join(problems))

    if not markdown.strip():
        raise EngineError(EXIT_NO_TEXT, "Markdown kosong")

    target = ctx.out("result.md")
    with open(target, "w", encoding="utf-8", newline="\n") as handle:
        handle.write(markdown)

    quality = safe_inspection("md", doc, target, {"method": method})
    if quality and ocr_pages:
        quality["checks"].insert(0, check("OCR", "info", "%d halaman scan dikenali lewat OCR" % ocr_pages, "Periksa ejaan hasil pengenalan."))
    done(target, ctx.name + ".md", {"method": method, "quality": quality, "ocr_pages": ocr_pages})


# -------------------------------------------------------------- organize


def op_merge(ctx):
    import pymupdf

    if len(ctx.inputs) < 2:
        raise EngineError(EXIT_GENERIC, "Minimal dua PDF")

    out = pymupdf.open()
    titles = ctx.options.get("_titles") or []
    toc = []
    for index, path in enumerate(ctx.inputs):
        progress(index, len(ctx.inputs), "Menggabungkan berkas %d dari %d" % (index + 1, len(ctx.inputs)))
        source = open_pdf(path, ctx)
        toc.append([1, str(titles[index])[:120] if index < len(titles) else "Dokumen %d" % (index + 1), out.page_count + 1])
        out.insert_pdf(source)
        source.close()

    if ctx.max_pages and out.page_count > ctx.max_pages:
        raise EngineError(EXIT_TOO_MANY_PAGES, "Terlalu banyak halaman")

    if truthy(ctx.options.get("bookmarks")):
        # Satu penanda per berkas asal, menggantikan penanda bawaan berkas.
        out.set_toc(toc)

    done(save_pdf(out, ctx.out("result.pdf")), "gabungan.pdf", {"pages": out.page_count})


def op_split(ctx):
    import pymupdf

    doc = open_pdf(ctx.inputs[0], ctx)
    total = doc.page_count
    mode = ctx.opt("mode", "ranges")

    if mode == "ranges":
        groups = parse_groups(ctx.opt("ranges"), total)
    elif mode == "pages":
        groups = [sorted(set(parse_pages(ctx.opt("pages"), total)))]
    elif mode == "every":
        groups = [[index] for index in range(total)]
    elif mode == "chunk":
        size = max(1, int(ctx.opt("chunk", 1)))
        groups = [list(range(start, min(start + size, total))) for start in range(0, total, size)]
    else:
        raise EngineError(EXIT_GENERIC, "Mode tidak dikenal")

    files = []
    for number, group in enumerate(groups, start=1):
        part = pymupdf.open()
        for index in group:
            part.insert_pdf(doc, from_page=index, to_page=index)
        first, last = group[0] + 1, group[-1] + 1
        label = "hal-%d" % first if first == last else "hal-%d-%d" % (first, last)
        if mode == "pages":
            label = "halaman-terpilih"
        target = ctx.out("part-%03d.pdf" % number)
        save_pdf(part, target)
        files.append((target, "%s-%s.pdf" % (ctx.name, label)))

    report = {"files": len(files), "pages": total}

    if len(files) == 1:
        done(files[0][0], files[0][1], report)
        return

    done(zip_outputs(ctx.out("result.zip"), files), ctx.name + "-dipisah.zip", report)


def op_organize(ctx):
    """Menyusun ulang halaman: urutan, hapus, putar, sisip kosong, tambah dari PDF lain."""
    import pymupdf

    sources = [open_pdf(ctx.inputs[0], ctx)]
    if "extra" in ctx.aux:
        sources.append(open_pdf(ctx.aux["extra"], ctx))

    spec = ctx.opt("spec")
    if isinstance(spec, str):
        spec = json.loads(spec)
    if not isinstance(spec, list) or not spec:
        raise EngineError(EXIT_INVALID_PAGES, "Susunan halaman kosong")

    out = pymupdf.open()
    base = sources[0][0].rect

    for item in spec:
        if item.get("blank"):
            out.new_page(width=base.width, height=base.height)
            continue

        source_index = int(item.get("src", 0))
        page_index = int(item.get("page", -1))
        if source_index >= len(sources) or not 0 <= page_index < sources[source_index].page_count:
            raise EngineError(EXIT_INVALID_PAGES, "Halaman tidak ditemukan")

        out.insert_pdf(sources[source_index], from_page=page_index, to_page=page_index)
        rotate = int(item.get("rotate", 0)) % 360
        if rotate:
            page = out[-1]
            page.set_rotation((page.rotation + rotate) % 360)

    if ctx.max_pages and out.page_count > ctx.max_pages:
        raise EngineError(EXIT_TOO_MANY_PAGES, "Terlalu banyak halaman")

    done(save_pdf(out, ctx.out("result.pdf")), ctx.name + "-disusun.pdf", {"pages": out.page_count})


def op_rotate(ctx):
    doc = open_pdf(ctx.inputs[0], ctx)
    angle = int(ctx.opt("angle", 90))
    if angle not in (90, 180, 270):
        raise EngineError(EXIT_GENERIC, "Sudut tidak valid")

    pages = scoped_pages(ctx, doc.page_count)
    for index in pages:
        page = doc[index]
        page.set_rotation((page.rotation + angle) % 360)

    done(save_pdf(doc, ctx.out("result.pdf")), ctx.name + "-diputar.pdf", {"pages_rotated": len(pages)})


def op_crop(ctx):
    import pymupdf

    doc = open_pdf(ctx.inputs[0], ctx)
    mode = ctx.opt("mode", "margins")
    pages = scoped_pages(ctx, doc.page_count)
    mm = 72 / 25.4

    for index in pages:
        page = doc[index]
        current = page.rect
        if mode == "box":
            box = ctx.opt("box")
            if isinstance(box, str):
                box = json.loads(box)
            if not box or len(box) != 4:
                raise EngineError(EXIT_GENERIC, "Area potong belum dipilih")
            visual = pymupdf.Rect(box[0] * current.width, box[1] * current.height, box[2] * current.width, box[3] * current.height)
        else:
            top, right, bottom, left = (float(ctx.opt(key, 0)) * mm for key in ("top", "right", "bottom", "left"))
            visual = pymupdf.Rect(left, top, current.width - right, current.height - bottom)

        if visual.width < 36 or visual.height < 36:
            raise EngineError(EXIT_GENERIC, "Area potong terlalu kecil")

        # Kotak potong dihitung pada tampilan halaman, lalu dipetakan ke ruang
        # MediaBox yang tidak diputar dan dibatasi agar tidak keluar halaman.
        unrotated = visual * page.derotation_matrix
        unrotated.normalize()
        crop = page.cropbox
        target = pymupdf.Rect(
            crop.x0 + unrotated.x0,
            crop.y0 + unrotated.y0,
            crop.x0 + unrotated.x1,
            crop.y0 + unrotated.y1,
        ) & page.mediabox
        page.set_cropbox(target)

    done(save_pdf(doc, ctx.out("result.pdf")), ctx.name + "-dipotong.pdf", {"pages_cropped": len(pages)})


# -------------------------------------------------------------- optimize


# Smart Compression: kompresi bertahap sampai ukuran target tercapai.
#
# Setiap "anak tangga" adalah kombinasi resolusi gambar (DPI) dan kualitas
# JPEG. Anak tangga pertama tanpa mengubah gambar (hanya optimasi struktur).
# Mesin mencari anak tangga paling ringan yang sudah memenuhi target, jadi
# kualitas tidak dikorbankan lebih dari yang diperlukan.

COMPRESSION_LADDER = [
    (None, None),
    (220, 85),
    (180, 80),
    (150, 75),
    (130, 68),
    (110, 60),
    (96, 52),
    (85, 45),
    (72, 38),
    (72, 30),
]

COMPRESSION_STRATEGIES = {
    # Batas bawah yang boleh dicapai tiap strategi.
    "quality": {"min_dpi": 150, "min_quality": 70},
    "balanced": {"min_dpi": 96, "min_quality": 50},
    "size": {"min_dpi": 72, "min_quality": 30},
}

LEGACY_LEVELS = {"low": "quality", "recommended": "balanced", "high": "size"}


def truthy(value, default=False):
    if value is None or value == "":
        return default
    if isinstance(value, bool):
        return value
    return str(value).lower() in ("1", "true", "on", "yes")


def compression_settings(options):
    """Membaca opsi kompresi (dipakai Smart Compression, Workflow, dan Assignment Pack)."""
    strategy = options.get("strategy") or LEGACY_LEVELS.get(options.get("level"), "balanced")
    if strategy not in COMPRESSION_STRATEGIES:
        strategy = "balanced"
    limits = dict(COMPRESSION_STRATEGIES[strategy])

    if options.get("min_dpi") not in (None, ""):
        limits["min_dpi"] = max(72, min(300, int(options["min_dpi"])))
    if options.get("min_quality") not in (None, ""):
        limits["min_quality"] = max(20, min(95, int(options["min_quality"])))

    target = None
    value = options.get("target_value")
    if value not in (None, ""):
        multiplier = 1024 * 1024 if str(options.get("target_unit", "MB")).upper() == "MB" else 1024
        target = int(float(value) * multiplier)

    return {
        "strategy": strategy,
        "target": target,
        "min_dpi": limits["min_dpi"],
        "min_quality": limits["min_quality"],
        "downsample": truthy(options.get("downsample"), True),
        "strip_metadata": truthy(options.get("strip_metadata"), True),
        "optimize_resources": truthy(options.get("optimize_resources"), True),
        "subset_fonts": truthy(options.get("subset_fonts"), True),
    }


def compression_rungs(settings):
    rungs = []
    for dpi, quality in COMPRESSION_LADDER:
        if dpi is not None:
            if quality < settings["min_quality"]:
                continue
            if not settings["downsample"]:
                dpi = None
            elif dpi < settings["min_dpi"]:
                continue
        rung = (dpi, quality)
        if rung not in rungs:
            rungs.append(rung)
    return rungs


def size_sources(doc, total):
    """Perkiraan porsi ukuran berkas yang berasal dari gambar."""
    seen = set()
    image_bytes = 0
    for page in doc:
        for image in page.get_images(full=True):
            xref = image[0]
            if xref in seen:
                continue
            seen.add(xref)
            try:
                image_bytes += len(doc.xref_stream_raw(xref) or b"")
            except Exception:  # noqa: BLE001
                continue
    return {
        "images": len(seen),
        "image_bytes": image_bytes,
        "image_share": round(min(1.0, image_bytes / total) * 100, 1) if total else 0,
    }


def compression_pass(source, target_path, rung, settings, ctx):
    dpi, quality = rung
    doc = open_pdf(source, ctx)
    try:
        if quality is not None:
            doc.rewrite_images(
                dpi_threshold=int(dpi * 1.1) + 1 if dpi else None,
                dpi_target=dpi or 0,
                quality=quality,
                lossy=True,
                lossless=settings["strategy"] != "quality",
                bitonal=False,
                color=True,
                gray=True,
            )
        if settings["subset_fonts"]:
            try:
                doc.subset_fonts()
            except Exception:  # noqa: BLE001
                pass
        if settings["strip_metadata"]:
            doc.set_metadata({})
            try:
                doc.del_xml_metadata()
            except Exception:  # noqa: BLE001
                pass
        optimize = settings["optimize_resources"]
        save_pdf(
            doc,
            target_path,
            garbage=4 if optimize else 1,
            clean=optimize,
            deflate_images=True,
            deflate_fonts=True,
            use_objstms=1 if optimize else 0,
        )
    finally:
        doc.close()
    return os.path.getsize(target_path)


def smart_compress(source, out_path, options, ctx):
    """Mengompres source ke out_path. Mengembalikan laporan berisi ukuran sebenarnya."""
    import time

    settings = compression_settings(options)
    before = os.path.getsize(source)
    target = settings["target"]
    budget = float(options.get("_time_budget") or 120)
    started = time.monotonic()

    probe = open_pdf(source, ctx)
    sources = size_sources(probe, before)
    probe.close()

    rungs = compression_rungs(settings)
    work_dir = os.path.join(os.path.dirname(out_path), "compress-passes")
    os.makedirs(work_dir, exist_ok=True)
    results = {}

    max_passes = 2 + max(1, (len(rungs) - 1).bit_length())

    def evaluate(index):
        if index not in results:
            progress(len(results), max_passes, "Mencoba kompresi tahap %d" % (len(results) + 1))
            path = os.path.join(work_dir, "pass-%02d.pdf" % index)
            results[index] = (compression_pass(source, path, rungs[index], settings, ctx), path)
        return results[index][0]

    def out_of_time():
        return time.monotonic() - started > budget

    last = len(rungs) - 1
    if target is None:
        # Tanpa target: pakai batas strategi apa adanya (perilaku kompresor biasa).
        chosen = last
        evaluate(chosen)
    elif evaluate(0) <= target:
        chosen = 0
    elif last == 0 or evaluate(last) > target:
        chosen = min(results, key=lambda index: results[index][0])
    else:
        # Pencarian biner: anak tangga paling ringan yang sudah memenuhi target.
        low, high = 1, last
        while low < high and not out_of_time():
            middle = (low + high) // 2
            if evaluate(middle) <= target:
                high = middle
            else:
                low = middle + 1
        chosen = high

    after, chosen_path = results[chosen]
    already_optimal = after >= before
    if already_optimal:
        shutil.copyfile(source, out_path)
        after = before
    else:
        shutil.copyfile(chosen_path, out_path)
    shutil.rmtree(work_dir, ignore_errors=True)

    dpi, quality = rungs[chosen]
    target_met = target is None or after <= target
    report = {
        "before": before,
        "after": after,
        "saved": before - after,
        "reduction": round((before - after) / before * 100, 1) if before else 0,
        "target": target,
        "target_met": target_met,
        "already_optimal": already_optimal,
        "strategy": settings["strategy"],
        "passes": len(results),
        "image_dpi": dpi,
        "image_quality": quality,
        "images_changed": quality is not None and not already_optimal,
        "sources": sources,
    }
    if not target_met:
        # Ukuran terkecil yang benar-benar dicapai, dibulatkan ke atas sebagai saran target.
        report["suggested_target"] = after
        if settings["strategy"] != "size" or not settings["downsample"]:
            report["hint"] = "strategy"
        elif sources["image_share"] < 40:
            report["hint"] = "few_images"
        else:
            report["hint"] = "limit"
    return report


def op_compress(ctx):
    source = ctx.inputs[0]
    target = ctx.out("result.pdf")
    report = smart_compress(source, target, ctx.options, ctx)
    done(target, ctx.name + "-kompres.pdf", report)


def op_repair(ctx):
    import pymupdf

    source = ctx.inputs[0]
    try:
        doc = pymupdf.open(source, filetype="pdf")
    except Exception:
        raise EngineError(EXIT_UNRECOVERABLE, "Struktur PDF tidak dapat dibaca")

    if doc.needs_pass:
        raise EngineError(EXIT_ENCRYPTED, "PDF diproteksi kata sandi")

    repaired_structure = bool(doc.is_repaired)
    total = doc.page_count
    out = pymupdf.open()
    failed_pages = []

    for index in range(total):
        try:
            page = doc[index]
            page.get_text("text")
            page.get_pixmap(dpi=20)
            out.insert_pdf(doc, from_page=index, to_page=index)
        except Exception:
            failed_pages.append(index + 1)

    if out.page_count == 0:
        raise EngineError(EXIT_UNRECOVERABLE, "Tidak ada halaman yang dapat dipulihkan")

    if failed_pages:
        status = "partial"
    elif repaired_structure:
        status = "repaired"
    else:
        status = "intact"

    done(save_pdf(out, ctx.out("result.pdf"), garbage=4, clean=True), ctx.name + "-diperbaiki.pdf", {
        "status": status,
        "pages_total": total,
        "pages_recovered": out.page_count,
        "failed_pages": failed_pages,
    })


_ocr_engine = None


def ocr_engine():
    global _ocr_engine
    if _ocr_engine is None:
        from rapidocr_onnxruntime import RapidOCR

        _ocr_engine = RapidOCR()
    return _ocr_engine


def needs_ocr(page):
    """Halaman tanpa teks, atau halaman bergambar yang teksnya hampir kosong."""
    text = page.get_text("text").strip()
    return text == "" or (len(text) < 20 and bool(page.get_images()))


def ocr_page(page, details=None, corrections=None, target=None, render_mode=3):
    """Menambahkan lapisan teks tak terlihat (render mode 3) dari hasil OCR.

    details menerima satu entri per baris: teks yang dikenali dan skor keyakinan
    asli dari mesin. corrections ({indeks baris: teks}) menggantikan teks baris
    yang dikoreksi Pengguna; posisi baris tetap dari hasil OCR. target dan
    render_mode dipakai PDF to Word: teks ditulis terlihat ke halaman baru.
    """
    import pymupdf

    pixmap = page.get_pixmap(dpi=OCR_DPI)
    result, _ = ocr_engine()(pixmap.tobytes("png"))
    scale = 72 / OCR_DPI
    words = 0
    if isinstance(corrections, list):
        # JSON dari klien bisa berupa daftar bila indeks barisnya berurutan dari nol.
        corrections = {str(index): value for index, value in enumerate(corrections) if value}
    corrections = corrections if isinstance(corrections, dict) else {}

    for line_index, (box, text, score) in enumerate(result or []):
        recognized = str(text).strip()
        corrected = corrections.get(str(line_index))
        text = str(corrected).strip() if isinstance(corrected, str) and corrected.strip() else recognized
        if details is not None:
            entry = {"text": recognized[:300], "score": round(float(score), 3), "corrected": text != recognized}
            if text != recognized:
                entry["applied"] = text[:300]
            details.append(entry)
        if not text:
            continue
        xs = [point[0] * scale for point in box]
        ys = [point[1] * scale for point in box]
        width, height = max(xs) - min(xs), max(ys) - min(ys)
        if width <= 0 or height <= 0:
            continue
        unit = pymupdf.get_text_length(text, fontname="helv", fontsize=1) or 1
        fontsize = max(1, min(height * 0.9, width / unit))
        origin = pymupdf.Point(min(xs), max(ys) - height * 0.2) * page.derotation_matrix
        (target or page).insert_text(origin, text, fontsize=fontsize, fontname="helv", render_mode=render_mode, rotate=page.rotation)
        words += len(text.split())

    return words


OCR_REVIEW_SCORE = 0.75
OCR_TEXT_BUDGET = 60000


def op_ocr(ctx):
    doc = open_pdf(ctx.inputs[0], ctx)
    selected = scoped_pages(ctx, doc.page_count)
    targets = [doc[index] for index in selected if needs_ocr(doc[index])]
    skipped = [index + 1 for index in selected if not needs_ocr(doc[index])]

    if not targets:
        raise EngineError(EXIT_NOTHING_TO_OCR, "Semua halaman sudah memiliki teks")

    corrections = ctx.opt("corrections", {})
    if isinstance(corrections, str):
        corrections = json.loads(corrections)
    if not isinstance(corrections, dict):
        corrections = {}

    words = 0
    pages, failed = [], []
    budget = OCR_TEXT_BUDGET
    for number, page in enumerate(targets, start=1):
        progress(number - 1, len(targets), "Mengenali teks halaman %d dari %d" % (number, len(targets)))
        lines = []
        try:
            page_words = ocr_page(page, lines, corrections.get(str(page.number + 1)))
        except Exception as error:  # noqa: BLE001
            # Satu halaman yang gagal tidak menghentikan halaman lain.
            sys.stderr.write("ocr page failed %s\n" % type(error).__name__)
            failed.append(page.number + 1)
            continue
        words += page_words
        scores = [line["score"] for line in lines]
        low = sum(1 for score in scores if score < OCR_REVIEW_SCORE)
        entry = {
            "page": page.number + 1,
            "words": page_words,
            "line_count": len(lines),
            "mean_score": round(sum(scores) / len(scores), 3) if scores else None,
            "low_lines": low,
            "review": page_words == 0 or (bool(scores) and low / float(len(scores)) > 0.2),
            "corrected": sum(1 for line in lines if line["corrected"]),
        }
        size = sum(len(line["text"]) for line in lines)
        if size <= budget:
            entry["lines"] = lines
            budget -= size
        pages.append(entry)
    progress(1, 1, "Menyimpan PDF yang bisa dicari")

    if words == 0:
        raise EngineError(EXIT_NO_TEXT, "Tidak ada teks yang terbaca")

    done(save_pdf(doc, ctx.out("result.pdf")), ctx.name + "-ocr.pdf", {
        "pages_total": doc.page_count,
        "pages_ocr": len(pages),
        "words": words,
        "engine": "RapidOCR (PP-OCR)",
        "review_score": OCR_REVIEW_SCORE,
        "pages": pages,
        "review_pages": [entry["page"] for entry in pages if entry["review"]],
        "failed_pages": failed,
        "skipped_pages": skipped,
        "text_truncated": any("lines" not in entry for entry in pages),
    })


def ocr_first(ctx, doc, editable=False):
    """PDF to Word/Markdown: OCR untuk halaman scan dulu, pada salinan di workspace.

    Markdown memakai lapisan teks tak terlihat. Word mengabaikan teks tak terlihat,
    jadi untuk Word (editable) halaman scan diganti halaman berisi teks OCR yang
    terlihat, tanpa gambar halaman scan. Mengembalikan (path, jumlah halaman).
    """
    import pymupdf

    targets = [page.number for page in doc if needs_ocr(page)]
    if not targets:
        return ctx.inputs[0], 0
    out = pymupdf.open() if editable else doc
    for page in doc:
        if page.number not in targets:
            if editable:
                out.insert_pdf(doc, from_page=page.number, to_page=page.number)
            continue
        position = targets.index(page.number) + 1
        progress(position - 1, len(targets), "OCR halaman scan %d dari %d" % (position, len(targets)))
        blank = out.new_page(width=page.rect.width, height=page.rect.height) if editable else None
        try:
            if editable:
                ocr_page(page, target=blank, render_mode=0)
            else:
                ocr_page(page)
        except Exception as error:  # noqa: BLE001
            sys.stderr.write("ocr page failed %s\n" % type(error).__name__)
    return save_pdf(out, ctx.out("ocr-source.pdf")), len(targets)


# ------------------------------------------------------------------- edit


ROMAN = [(1000, "m"), (900, "cm"), (500, "d"), (400, "cd"), (100, "c"), (90, "xc"), (50, "l"), (40, "xl"), (10, "x"), (9, "ix"), (5, "v"), (4, "iv"), (1, "i")]


def roman(number):
    result = ""
    for value, symbol in ROMAN:
        while number >= value:
            result += symbol
            number -= value
    return result


def page_number_label(fmt, number, total):
    if fmt == "n_of_total":
        return "%d / %d" % (number, total)
    if fmt == "page_n":
        return "Halaman %d" % number
    if fmt == "page_n_of_total":
        return "Halaman %d dari %d" % (number, total)
    if fmt == "roman_lower":
        return roman(number)
    if fmt == "roman_upper":
        return roman(number).upper()
    return str(number)


FONTS = {"helvetica": "helv", "times": "tiro", "courier": "cour"}


def stamp_label(page, label, vertical, align, size, fontname, color, margin=28):
    import pymupdf

    width = pymupdf.get_text_length(label, fontname=fontname, fontsize=size)
    visual = page.rect
    x = {"left": margin, "right": visual.width - margin - width}.get(align, (visual.width - width) / 2)
    y = margin + size if vertical == "top" else visual.height - margin
    point = pymupdf.Point(x, y) * page.derotation_matrix
    page.insert_text(point, label, fontsize=size, fontname=fontname, color=color, rotate=page.rotation)


def op_pagenumbers(ctx):
    import pymupdf

    doc = open_pdf(ctx.inputs[0], ctx)
    total_pages = doc.page_count
    first = int(ctx.opt("first_page", 1))
    last = int(ctx.opt("last_page", total_pages))
    if first < 1 or last > total_pages or first > last:
        raise EngineError(EXIT_INVALID_PAGES, "Rentang halaman tidak valid")

    start = int(ctx.opt("start", 1))
    size = float(ctx.opt("size", 11))
    fontname = FONTS.get(ctx.opt("font", "helvetica"), "helv")
    color = hex_color(ctx.opt("color"), (0.07, 0.09, 0.15))
    vertical = ctx.opt("position", "bottom")
    align = ctx.opt("align", "center")
    fmt = ctx.opt("format", "n")
    prefix, suffix = str(ctx.opt("prefix", "")), str(ctx.opt("suffix", ""))
    numbered_total = start + (last - first)

    for offset, index in enumerate(range(first - 1, last)):
        label = prefix + page_number_label(fmt, start + offset, numbered_total) + suffix
        stamp_label(doc[index], label, vertical, align, size, fontname, color)

    done(save_pdf(doc, ctx.out("result.pdf")), ctx.name + "-bernomor.pdf", {"pages_numbered": last - first + 1})


def watermark_anchor(page, position, width, height):
    margin = 36
    visual = page.rect
    positions = {
        "center": ((visual.width - width) / 2, (visual.height - height) / 2),
        "top-left": (margin, margin),
        "top-right": (visual.width - width - margin, margin),
        "bottom-left": (margin, visual.height - height - margin),
        "bottom-right": (visual.width - width - margin, visual.height - height - margin),
    }
    return positions.get(position, positions["center"])


def op_watermark(ctx):
    import pymupdf
    from PIL import Image

    doc = open_pdf(ctx.inputs[0], ctx)
    kind = ctx.opt("type", "text")
    opacity = max(0.05, min(1.0, float(ctx.opt("opacity", 30)) / 100))
    rotation = float(ctx.opt("rotation", 0))
    position = ctx.opt("position", "center")
    pages = scoped_pages(ctx, doc.page_count)

    image_data = None
    if kind == "image":
        if "image" not in ctx.aux:
            raise EngineError(EXIT_GENERIC, "Gambar watermark belum dipilih")
        with Image.open(ctx.aux["image"]) as image:
            prepared = image.convert("RGBA").rotate(rotation, expand=True, resample=Image.BICUBIC)
        alpha = prepared.getchannel("A").point(lambda value: int(value * opacity))
        prepared.putalpha(alpha)
        buffer = io.BytesIO()
        prepared.save(buffer, format="PNG")
        image_data = (buffer.getvalue(), prepared.size)

    for index in pages:
        page = doc[index]
        if kind == "image":
            data, (img_w, img_h) = image_data
            target_width = page.rect.width * max(5, min(100, float(ctx.opt("size", 40)))) / 100
            target_height = target_width * img_h / img_w
            x, y = watermark_anchor(page, position, target_width, target_height)
            rect = pymupdf.Rect(x, y, x + target_width, y + target_height) * page.derotation_matrix
            page.insert_image(rect, stream=data, overlay=True, keep_proportion=True)
            continue

        text = str(ctx.opt("text", "")).strip()
        if not text:
            raise EngineError(EXIT_GENERIC, "Teks watermark kosong")
        size = float(ctx.opt("size", 48))
        width = pymupdf.get_text_length(text, fontname="helv", fontsize=size)
        x, y = watermark_anchor(page, position, width, size)
        center = pymupdf.Point(x + width / 2, y + size / 2)
        origin = pymupdf.Point(x, y + size * 0.8)
        matrix = pymupdf.Matrix(-rotation - page.rotation)
        page.insert_text(
            origin * page.derotation_matrix,
            text,
            fontsize=size,
            fontname="helv",
            color=hex_color(ctx.opt("color"), (0.85, 0.28, 0.2)),
            fill_opacity=opacity,
            morph=(center * page.derotation_matrix, matrix),
        )

    done(save_pdf(doc, ctx.out("result.pdf")), ctx.name + "-watermark.pdf", {"pages_marked": len(pages)})


def insert_overlay_image(page, rect, data):
    page.insert_image(rect, stream=data, overlay=True, keep_proportion=True, rotate=page.rotation)


def op_overlay(ctx):
    """Edit PDF dan Sign PDF: menambahkan objek di atas halaman.

    Teks, bentuk, garis, coretan, highlight, dan komentar menjadi anotasi PDF
    sungguhan sehingga tetap dapat diubah atau dihapus di aplikasi pembaca PDF.
    Gambar dan tanda tangan ditanam ke isi halaman. Teks asli PDF tidak diubah.
    """
    import pymupdf

    doc = open_pdf(ctx.inputs[0], ctx)
    objects = ctx.opt("objects", [])
    if isinstance(objects, str):
        objects = json.loads(objects)
    if not objects:
        raise EngineError(EXIT_NOTHING_FOUND, "Belum ada objek yang ditambahkan")

    counts = {}
    for item in objects:
        index = int(item.get("page", 0))
        if not 0 <= index < doc.page_count:
            raise EngineError(EXIT_INVALID_PAGES, "Halaman objek tidak valid")
        page = doc[index]
        kind = item.get("type")
        color = hex_color(item.get("color"), (0.85, 0.28, 0.2))
        width = float(item.get("width", 2))
        rect = visual_rect(page, item["rect"]) if "rect" in item else None
        annot = None

        if kind == "text":
            fontsize = float(item.get("fontsize", 14))
            annot = page.add_freetext_annot(
                rect, str(item.get("text", "")), fontsize=fontsize, fontname="helv",
                text_color=color, rotate=page.rotation,
            )
        elif kind == "rect":
            annot = page.add_rect_annot(rect)
        elif kind == "ellipse":
            annot = page.add_circle_annot(rect)
        elif kind == "line":
            start, end = item["points"][0], item["points"][1]
            annot = page.add_line_annot(visual_point(page, start), visual_point(page, end))
        elif kind == "ink":
            strokes = [
                [tuple(visual_point(page, point)) for point in stroke]
                for stroke in item.get("strokes", [])
                if len(stroke) > 1
            ]
            if not strokes:
                continue
            annot = page.add_ink_annot(strokes)
        elif kind == "highlight":
            annot = page.add_highlight_annot(rect)
        elif kind == "note":
            annot = page.add_text_annot(rect.tl, str(item.get("text", "")), icon="Comment")
        elif kind in ("image", "signature"):
            insert_overlay_image(page, rect, decode_data_url(item.get("data")))
        else:
            raise EngineError(EXIT_GENERIC, "Jenis objek tidak dikenal")

        if annot is not None:
            if kind in ("rect", "ellipse", "line", "ink"):
                fill = item.get("fill")
                annot.set_colors(stroke=color, fill=hex_color(fill) if fill else None)
                annot.set_border(width=width)
                annot.set_opacity(float(item.get("opacity", 1)))
            elif kind == "highlight":
                annot.set_colors(stroke=hex_color(item.get("color"), (1, 0.85, 0.2)))
            elif kind == "note":
                annot.set_colors(stroke=color)
            annot.update()

        counts[kind] = counts.get(kind, 0) + 1

    suffix = "-ditandatangani.pdf" if ctx.opt("mode") == "sign" else "-diedit.pdf"
    done(save_pdf(doc, ctx.out("result.pdf")), ctx.name + suffix, {"objects": counts})


# --------------------------------------------------------------- security


def op_protect(ctx):
    import pymupdf

    doc = open_pdf(ctx.inputs[0], ctx)
    password = str(ctx.opt("password", ""))
    if len(password) < 6:
        raise EngineError(EXIT_GENERIC, "Kata sandi terlalu pendek")

    owner_password = str(ctx.opt("owner_password", "")) or secrets.token_urlsafe(24)
    permissions = pymupdf.PDF_PERM_ACCESSIBILITY
    flags = {
        "print": pymupdf.PDF_PERM_PRINT | pymupdf.PDF_PERM_PRINT_HQ,
        "copy": pymupdf.PDF_PERM_COPY,
        "modify": pymupdf.PDF_PERM_MODIFY | pymupdf.PDF_PERM_ASSEMBLE,
        "annotate": pymupdf.PDF_PERM_ANNOTATE | pymupdf.PDF_PERM_FORM,
    }
    allowed = ctx.opt("permissions", [])
    for key in allowed if isinstance(allowed, list) else []:
        permissions |= flags.get(key, 0)

    target = ctx.out("result.pdf")
    doc.save(
        target,
        garbage=3,
        deflate=True,
        encryption=pymupdf.PDF_ENCRYPT_AES_256,
        user_pw=password,
        owner_pw=owner_password,
        permissions=permissions,
    )

    check = pymupdf.open(target)
    if not check.needs_pass:
        raise EngineError(EXIT_GENERIC, "Enkripsi gagal diterapkan")

    done(target, ctx.name + "-terproteksi.pdf", {"encryption": "AES-256", "permissions": sorted(allowed) if isinstance(allowed, list) else []})


def op_unlock(ctx):
    doc = open_pdf(ctx.inputs[0], ctx, allow_encrypted=True)
    if not doc.is_encrypted and not doc.needs_pass:
        raise EngineError(EXIT_NOT_PROTECTED, "PDF tidak diproteksi")

    password = str(ctx.opt("password", ""))
    result = doc.authenticate(password) if password else 0

    # Bit 4 = kata sandi pemilik. Tanpa kata sandi pengguna, pembatasan izin
    # hanya boleh dibuka dengan kata sandi pemilik, bukan dilewati.
    if doc.needs_pass and result == 0:
        raise EngineError(EXIT_WRONG_PASSWORD, "Kata sandi salah")
    if not doc.needs_pass and not (result & 4):
        raise EngineError(EXIT_WRONG_PASSWORD, "Butuh kata sandi pemilik")

    target = ctx.out("result.pdf")
    doc.save(target, garbage=3, deflate=True, encryption=1)
    done(target, ctx.name + "-terbuka.pdf")


def op_redact(ctx):
    import pymupdf

    doc = open_pdf(ctx.inputs[0], ctx)
    areas = ctx.opt("areas", [])
    if isinstance(areas, str):
        areas = json.loads(areas)
    terms = [term.strip() for term in str(ctx.opt("terms", "")).splitlines() if term.strip()]

    marks = 0
    touched = set()
    marked_areas = []
    for area in areas or []:
        index = int(area.get("page", 0))
        if not 0 <= index < doc.page_count:
            raise EngineError(EXIT_INVALID_PAGES, "Halaman area tidak valid")
        page = doc[index]
        rect = visual_rect(page, area["rect"])
        page.add_redact_annot(rect, fill=(0, 0, 0))
        marked_areas.append((index, rect))
        marks += 1
        touched.add(index)

    for term in terms:
        for page in doc:
            for rect in page.search_for(term):
                page.add_redact_annot(rect, fill=(0, 0, 0))
                marks += 1
                touched.add(page.number)

    if marks == 0:
        raise EngineError(EXIT_NOTHING_FOUND, "Tidak ada yang ditandai")

    for index in touched:
        # Teks, gambar, dan grafik di bawah area benar-benar dihapus dari isi halaman.
        doc[index].apply_redactions(images=pymupdf.PDF_REDACT_IMAGE_PIXELS, graphics=1, text=0)

    # MuPDF membiarkan karakter di tepi area yang hanya tertutup sebagian. Karakter
    # seperti itu tetap bisa disalin, jadi ikut dihapus pada lintasan kedua.
    for index, rect in marked_areas:
        page = doc[index]
        edges = [
            pymupdf.Rect(char["bbox"])
            for block in page.get_text("rawdict", clip=rect + (1, 1, -1, -1)).get("blocks", [])
            for line in block.get("lines", [])
            for span in line.get("spans", [])
            for char in span.get("chars", [])
            if char.get("c", "").strip()
        ]
        if edges:
            for edge in edges:
                page.add_redact_annot(edge, fill=(0, 0, 0))
            page.apply_redactions(images=pymupdf.PDF_REDACT_IMAGE_PIXELS, graphics=1, text=0)

    # Bersihkan metadata, lampiran, JavaScript, dan teks tersembunyi.
    doc.scrub(redactions=False, reset_fields=False)
    doc.set_metadata({})

    # Verifikasi: tidak ada kata kunci yang masih bisa dicari, dan tidak ada teks
    # yang masih bisa diekstrak di dalam area (dipersempit 1 pt agar teks tetangga
    # yang hanya bersinggungan dengan tepi area tidak ikut terhitung).
    remaining = sum(len(page.search_for(term)) for term in terms for page in doc)
    for index, rect in marked_areas:
        inner = rect + (1, 1, -1, -1)
        if not inner.is_empty and doc[index].get_text("text", clip=inner).strip():
            remaining += 1
    if remaining:
        raise EngineError(EXIT_GENERIC, "Redaksi tidak lengkap")

    target = ctx.out("result.pdf")
    save_pdf(doc, target, garbage=4, clean=True)

    # Periksa ulang berkas yang sudah disimpan, bukan hanya dokumen di memori.
    saved = open_pdf(target)
    leaked = sum(len(page.search_for(term)) for term in terms for page in saved)
    leaked += sum(1 for key in ("title", "author", "subject", "keywords") if (saved.metadata or {}).get(key))
    saved.close()
    if leaked:
        raise EngineError(EXIT_GENERIC, "Redaksi tidak lengkap")

    done(target, ctx.name + "-diredaksi.pdf", {
        "marks": marks,
        "pages": len(touched),
        "verified": {"terms": len(terms), "areas": len(marked_areas), "text_remaining": 0, "metadata_cleared": True},
    })


def doc_words(doc):
    words = []
    for page in doc:
        for x0, y0, x1, y1, text, *_ in page.get_text("words"):
            import pymupdf

            words.append((text, page.number, to_visual_box(page, pymupdf.Rect(x0, y0, x1, y1))))
    return words


def op_compare(ctx):
    import pymupdf

    if len(ctx.inputs) != 2:
        raise EngineError(EXIT_GENERIC, "Butuh dua PDF")

    first = open_pdf(ctx.inputs[0], ctx)
    second = open_pdf(ctx.inputs[1], ctx)
    words_a, words_b = doc_words(first), doc_words(second)

    if not words_a and not words_b:
        raise EngineError(EXIT_NO_TEXT, "Kedua PDF tidak memiliki teks")

    limit = 80000
    words_a, words_b = words_a[:limit], words_b[:limit]
    matcher = difflib.SequenceMatcher(a=[w[0] for w in words_a], b=[w[0] for w in words_b], autojunk=False)

    changes = []
    for tag, a0, a1, b0, b1 in matcher.get_opcodes():
        if tag == "equal":
            continue
        changes.append({
            "type": {"insert": "added", "delete": "removed", "replace": "changed"}[tag],
            "a_text": " ".join(w[0] for w in words_a[a0:a1])[:600],
            "b_text": " ".join(w[0] for w in words_b[b0:b1])[:600],
            "a": [{"page": w[1], "box": w[2]} for w in words_a[a0:a1]][:200],
            "b": [{"page": w[1], "box": w[2]} for w in words_b[b0:b1]][:200],
        })

    # Salinan yang ditandai: merah untuk yang dihapus (versi lama), hijau untuk yang ditambahkan.
    for change in changes:
        for doc, key, color in ((first, "a", (0.86, 0.15, 0.15)), (second, "b", (0.09, 0.64, 0.29))):
            for word in change[key]:
                page = doc[word["page"]]
                annot = page.add_highlight_annot(visual_rect(page, word["box"]))
                annot.set_colors(stroke=color)
                annot.update()

    files = []
    for doc, label, index in ((first, "versi-lama", 0), (second, "versi-baru", 1)):
        target = ctx.out("compare-%d.pdf" % index)
        save_pdf(doc, target)
        files.append((target, "%s-%s-ditandai.pdf" % (ctx.name, label)))

    report = {
        "changes": changes[:500],
        "truncated": len(changes) > 500,
        "summary": {
            "added": sum(1 for c in changes if c["type"] == "added"),
            "removed": sum(1 for c in changes if c["type"] == "removed"),
            "changed": sum(1 for c in changes if c["type"] == "changed"),
        },
        "pages": [first.page_count, second.page_count],
    }

    done(zip_outputs(ctx.out("result.zip"), files), ctx.name + "-perbandingan.zip", report)


# ------------------------------------------------------------------ forms


def op_forms(ctx):
    import pymupdf

    doc = open_pdf(ctx.inputs[0], ctx)
    values = ctx.opt("fields", {})
    if isinstance(values, str):
        values = json.loads(values)
    new_fields = ctx.opt("new_fields", [])
    if isinstance(new_fields, str):
        new_fields = json.loads(new_fields)

    filled = 0
    existing = 0
    for page in doc:
        for widget in page.widgets():
            existing += 1
            name = widget.field_name
            if name not in values:
                continue
            value = values[name]
            if widget.field_type == pymupdf.PDF_WIDGET_TYPE_CHECKBOX:
                widget.field_value = widget.on_state() if value in (True, "true", "1", 1, "on") else "Off"
            elif widget.field_type == pymupdf.PDF_WIDGET_TYPE_RADIOBUTTON:
                widget.field_value = widget.on_state() if str(value) == str(widget.on_state()) else "Off"
            else:
                widget.field_value = str(value)
            widget.update()
            filled += 1

    added = 0
    for field in new_fields or []:
        index = int(field.get("page", 0))
        if not 0 <= index < doc.page_count:
            raise EngineError(EXIT_INVALID_PAGES, "Halaman field tidak valid")
        page = doc[index]
        widget = pymupdf.Widget()
        widget.rect = visual_rect(page, field["rect"])
        widget.field_name = re.sub(r"[^A-Za-z0-9_\-]", "_", str(field.get("name") or "field_%d" % (added + 1)))[:60]
        if field.get("type") == "checkbox":
            widget.field_type = pymupdf.PDF_WIDGET_TYPE_CHECKBOX
            widget.field_value = bool(field.get("value"))
        else:
            widget.field_type = pymupdf.PDF_WIDGET_TYPE_TEXT
            widget.field_value = str(field.get("value", ""))
            widget.text_fontsize = 0
        page.add_widget(widget)
        added += 1

    if existing == 0 and added == 0:
        raise EngineError(EXIT_NO_FIELDS, "Tidak ada field formulir")

    if ctx.opt("flatten") in (True, "1", "true", 1):
        doc.bake(annots=False, widgets=True)

    done(save_pdf(doc, ctx.out("result.pdf")), ctx.name + "-formulir.pdf", {"filled": filled, "added": added})


# ------------------------------------------------------------------- scan


SCAN_DEFAULT_CORNERS = [[0.03, 0.03], [0.97, 0.03], [0.97, 0.97], [0.03, 0.97]]


def order_corners(points):
    """Empat titik menjadi urutan kiri atas, kanan atas, kanan bawah, kiri bawah."""
    import numpy

    points = numpy.array(points, dtype="float32")
    sums = points.sum(axis=1)
    diffs = numpy.diff(points, axis=1).ravel()
    return numpy.array([points[sums.argmin()], points[diffs.argmin()], points[sums.argmax()], points[diffs.argmax()]], dtype="float32")


def detect_paper(frame):
    """Tepi kertas pada foto: kontur empat sisi terbesar yang menutup minimal 20% foto."""
    import cv2

    height, width = frame.shape[:2]
    scale = 900.0 / max(height, width)
    small = cv2.resize(frame, (max(1, int(width * scale)), max(1, int(height * scale)))) if scale < 1 else frame
    scale = min(scale, 1.0)
    gray = cv2.GaussianBlur(cv2.cvtColor(small, cv2.COLOR_BGR2GRAY), (5, 5), 0)
    edges = cv2.dilate(cv2.Canny(gray, 50, 150), cv2.getStructuringElement(cv2.MORPH_RECT, (5, 5)))
    contours, _ = cv2.findContours(edges, cv2.RETR_LIST, cv2.CHAIN_APPROX_SIMPLE)
    minimum = 0.2 * small.shape[0] * small.shape[1]
    for contour in sorted(contours, key=cv2.contourArea, reverse=True)[:8]:
        if cv2.contourArea(contour) < minimum:
            break
        approx = cv2.approxPolyDP(contour, 0.02 * cv2.arcLength(contour, True), True)
        if len(approx) == 4 and cv2.isContourConvex(approx):
            return order_corners(approx.reshape(4, 2) / scale)
    return None


def enhance_scan(frame, mode):
    """Perjelas hasil foto dokumen: ratakan bayangan, naikkan kontras, redam noise."""
    import cv2
    import numpy

    def flatten(plane):
        background = cv2.medianBlur(cv2.dilate(plane, numpy.ones((7, 7), numpy.uint8)), 21)
        return cv2.normalize(255 - cv2.absdiff(plane, background), None, 0, 255, cv2.NORM_MINMAX)

    if mode == "color":
        planes = [flatten(plane) for plane in cv2.split(frame)]
        merged = cv2.merge(planes)
        lab = cv2.cvtColor(merged, cv2.COLOR_BGR2LAB)
        light, a, b = cv2.split(lab)
        light = cv2.createCLAHE(clipLimit=2.0, tileGridSize=(8, 8)).apply(light)
        return cv2.medianBlur(cv2.cvtColor(cv2.merge((light, a, b)), cv2.COLOR_LAB2BGR), 3)

    gray = flatten(cv2.cvtColor(frame, cv2.COLOR_BGR2GRAY))
    if mode == "bw":
        return cv2.adaptiveThreshold(cv2.medianBlur(gray, 3), 255, cv2.ADAPTIVE_THRESH_GAUSSIAN_C, cv2.THRESH_BINARY, 31, 15)
    return cv2.medianBlur(cv2.createCLAHE(clipLimit=2.0, tileGridSize=(8, 8)).apply(gray), 3)


def op_scan(ctx):
    import cv2
    import numpy
    import pymupdf
    from PIL import Image, ImageOps

    transforms = ctx.opt("transforms", [])
    if isinstance(transforms, str):
        transforms = json.loads(transforms)

    auto_edges = ctx.opt("auto_edges", "1") in (True, "1", "true", 1, "on")
    enhance = ctx.opt("enhance", "none")
    detected = 0

    out = pymupdf.open()
    for index, path in enumerate(ctx.inputs):
        progress(index, len(ctx.inputs), "Meluruskan foto %d dari %d" % (index + 1, len(ctx.inputs)))
        settings = transforms[index] if index < len(transforms) and isinstance(transforms[index], dict) else {}
        with Image.open(path) as image:
            upright = ImageOps.exif_transpose(image).convert("RGB")
        frame = cv2.cvtColor(numpy.array(upright), cv2.COLOR_RGB2BGR)
        height, width = frame.shape[:2]

        corners = settings.get("corners")
        untouched = not corners or all(
            abs(float(value) - expected) < 1e-4
            for point, default in zip(corners, SCAN_DEFAULT_CORNERS) for value, expected in zip(point, default)
        )
        found = detect_paper(frame) if auto_edges and untouched else None
        if found is not None:
            detected += 1
            corners = [[x / width, y / height] for x, y in found]
        if corners and len(corners) == 4:
            source = numpy.array([[x * width, y * height] for x, y in corners], dtype="float32")
            top = numpy.linalg.norm(source[1] - source[0])
            bottom = numpy.linalg.norm(source[2] - source[3])
            left = numpy.linalg.norm(source[3] - source[0])
            right = numpy.linalg.norm(source[2] - source[1])
            out_w, out_h = int(max(top, bottom)), int(max(left, right))
            if out_w > 20 and out_h > 20:
                target = numpy.array([[0, 0], [out_w - 1, 0], [out_w - 1, out_h - 1], [0, out_h - 1]], dtype="float32")
                frame = cv2.warpPerspective(frame, cv2.getPerspectiveTransform(source, target), (out_w, out_h))

        rotate = int(settings.get("rotate", 0)) % 360
        rotations = {90: cv2.ROTATE_90_CLOCKWISE, 180: cv2.ROTATE_180, 270: cv2.ROTATE_90_COUNTERCLOCKWISE}
        if rotate in rotations:
            frame = cv2.rotate(frame, rotations[rotate])

        if enhance in ("color", "gray", "bw"):
            longest = max(frame.shape[:2])
            if longest > 2600:
                ratio = 2600.0 / longest
                frame = cv2.resize(frame, (int(frame.shape[1] * ratio), int(frame.shape[0] * ratio)), interpolation=cv2.INTER_AREA)
            frame = enhance_scan(frame, enhance)

        ok, encoded = cv2.imencode(".png", frame) if enhance == "bw" else cv2.imencode(".jpg", frame, [cv2.IMWRITE_JPEG_QUALITY, 88])
        if not ok:
            raise EngineError(EXIT_GENERIC, "Gambar tidak dapat diproses")
        fit_image_page(out, encoded.tobytes(), frame.shape[1], frame.shape[0])

    report = {"pages": out.page_count, "ocr": False, "edges_detected": detected, "enhance": enhance}
    if ctx.opt("ocr") in (True, "1", "true", 1):
        words = sum(ocr_page(page) for page in out)
        report.update({"ocr": True, "words": words})

    done(save_pdf(out, ctx.out("result.pdf")), ctx.name + "-scan.pdf", report)


# --------------------------------------------------------------------- AI


def op_info(ctx):
    """Jumlah halaman dan keberadaan teks, untuk pemeriksaan sebelum penerjemahan."""
    doc = open_pdf(ctx.inputs[0], ctx)
    has_text = any(page.get_text("text").strip() for page in doc)
    target = ctx.out("info.json")
    with open(target, "w", encoding="utf-8") as handle:
        json.dump({"pages": doc.page_count, "has_text": has_text}, handle)
    done(target, "info.json", {"pages": doc.page_count, "has_text": has_text})


def op_extract_blocks(ctx):
    """Blok teks beserta posisinya, untuk Translate PDF."""
    doc = open_pdf(ctx.inputs[0], ctx)
    ensure_text(doc)
    blocks = []
    for page in doc:
        for block in page.get_text("dict")["blocks"]:
            if block.get("type") != 0:
                continue
            lines = [" ".join(span["text"] for span in line["spans"]).strip() for line in block["lines"]]
            text = "\n".join(line for line in lines if line)
            if not text.strip():
                continue
            spans = [span for line in block["lines"] for span in line["spans"]]
            size = max((span["size"] for span in spans), default=11)
            bold = any("bold" in span["font"].lower() for span in spans)
            color = spans[0]["color"] if spans else 0
            blocks.append({
                "id": "b%d" % len(blocks),
                "page": page.number,
                "bbox": list(block["bbox"]),
                "text": text,
                "size": round(size, 1),
                "bold": bold,
                "color": "#%06x" % color,
            })
    target = ctx.out("blocks.json")
    with open(target, "w", encoding="utf-8") as handle:
        json.dump(blocks, handle, ensure_ascii=False)
    done(target, "blocks.json", {"blocks": len(blocks), "pages": doc.page_count})


def html_escape(value):
    return value.replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;")


def op_apply_translation(ctx):
    """Mengganti teks asli dengan terjemahan pada posisi yang sama."""
    import pymupdf

    doc = open_pdf(ctx.inputs[0], ctx)
    with open(ctx.aux["blocks"], encoding="utf-8") as handle:
        blocks = json.load(handle)
    with open(ctx.aux["translations"], encoding="utf-8") as handle:
        translations = json.load(handle)

    by_page = {}
    for block in blocks:
        if block["id"] in translations:
            by_page.setdefault(block["page"], []).append(block)

    replaced = 0
    for index, items in by_page.items():
        page = doc[index]
        for block in items:
            page.add_redact_annot(pymupdf.Rect(block["bbox"]), fill=None)
        # Hanya teks yang dihapus; gambar dan grafik dibiarkan.
        page.apply_redactions(images=pymupdf.PDF_REDACT_IMAGE_NONE, graphics=0)
        for block in items:
            rect = pymupdf.Rect(block["bbox"])
            text = html_escape(str(translations[block["id"]])).replace("\n", "<br>")
            weight = "bold" if block.get("bold") else "normal"
            css = "* {font-family: sans-serif; font-size: %spx; color: %s; font-weight: %s;}" % (block["size"], block["color"], weight)
            # Kotak diperbesar sedikit ke bawah; scale_low mengecilkan huruf bila terjemahan lebih panjang.
            page.insert_htmlbox(rect + (0, 0, 0, block["size"]), text, css=css, scale_low=0.4)
            replaced += 1

    done(save_pdf(doc, ctx.out("result.pdf")), ctx.name + "-terjemahan.pdf", {"blocks": replaced})


# ---------------------------------------------------------------- workflow


# ----------------------------------------------------------- assignment pack


SECTION_ORDER = ["cover", "preliminary", "main", "appendix"]
SECTION_NAMES = {"cover": "Sampul", "preliminary": "Bagian awal", "main": "Isi utama", "appendix": "Lampiran"}
LABEL_STYLES = {"arabic": "D", "roman_lower": "r", "roman_upper": "R"}


def json_option(value, default):
    if isinstance(value, str):
        try:
            return json.loads(value)
        except ValueError:
            raise EngineError(EXIT_GENERIC, "Opsi tidak valid")
    return default if value is None else value


def format_number(style, number):
    if style == "roman_lower":
        return roman(number)
    if style == "roman_upper":
        return roman(number).upper()
    return str(number)


def op_pack(ctx):
    """Assignment Pack Builder: menyusun PDF, gambar, dan hasil konversi Word menjadi satu PDF."""
    import pymupdf

    options = ctx.options
    sections = json_option(options.get("sections"), [])
    titles = options.get("_titles") or []
    kinds = options.get("_kinds") or []

    entries = []
    for index, path in enumerate(ctx.inputs):
        section = sections[index] if index < len(sections) and sections[index] in SECTION_ORDER else "main"
        entries.append({
            "path": path,
            "section": section,
            "title": str(titles[index]) if index < len(titles) else "Dokumen %d" % (index + 1),
            "kind": kinds[index] if index < len(kinds) else "pdf",
        })
    # Urutan akhir selalu mengikuti urutan bagian; di dalam bagian, urutan Pengguna.
    entries.sort(key=lambda entry: SECTION_ORDER.index(entry["section"]))

    paper = ctx.opt("paper", "original")
    orientation = ctx.opt("orientation", "auto")
    margin = max(0.0, min(40.0, float(ctx.opt("margin_mm", 0)))) * MM

    out = pymupdf.open()
    documents = []
    compressing = truthy((json_option(options.get("compression"), {}) or {}).get("enabled"))
    build_share = 0.7 if compressing else 0.9
    for position, entry in enumerate(entries):
        with progress_scope(0, build_share):
            progress(position, len(entries), "Menyusun dokumen %d dari %d" % (position + 1, len(entries)))
        start = out.page_count
        if entry["kind"] == "image":
            data, width, height = image_bytes_upright(entry["path"])
            place_image(out, data, width, height, paper if paper in PAPER_SIZES else "a4", orientation, margin)
        else:
            source = open_pdf(entry["path"], ctx)
            if paper not in PAPER_SIZES:
                out.insert_pdf(source)
            else:
                for page in source:
                    visual = page.rect
                    page_width, page_height = oriented(PAPER_SIZES[paper], orientation, visual.width > visual.height)
                    target = out.new_page(width=page_width, height=page_height)
                    area = pymupdf.Rect(margin, margin, page_width - margin, page_height - margin)
                    if page.rotation:
                        # Rotasi dipanggang dulu agar tampilan halaman tetap sama setelah ditempatkan ulang.
                        upright = pymupdf.open()
                        upright.insert_pdf(source, from_page=page.number, to_page=page.number)
                        upright[0].remove_rotation()
                        target.show_pdf_page(area, upright, 0, keep_proportion=True)
                        upright.close()
                    else:
                        target.show_pdf_page(area, source, page.number, keep_proportion=True)
            source.close()

        if ctx.max_pages and out.page_count > ctx.max_pages:
            raise EngineError(EXIT_TOO_MANY_PAGES, "Terlalu banyak halaman")
        documents.append({"title": entry["title"], "section": entry["section"], "start": start + 1, "pages": out.page_count - start})

    total = out.page_count
    section_of = []
    first_pages = set()
    for document in documents:
        section_of.extend([document["section"]] * document["pages"])
        first_pages.add(document["start"] - 1)

    # ------------------------------------------------------ penomoran akademik
    numbering = json_option(options.get("numbering"), {})
    vertical = ctx.opt("number_position", "bottom")
    align = ctx.opt("number_align", "center")
    size = float(ctx.opt("number_size", 11))
    fontname = FONTS.get(ctx.opt("number_font", "times"), "tiro")
    first_rule = ctx.opt("first_page_rule", "same")
    hidden = set(parse_pages(ctx.opt("hide_pages"), total)) if ctx.opt("hide_pages") else set()

    labels = []
    page_label_ranges = []
    current_section = None
    style = "none"
    number = 0
    last_number = 0
    with progress_scope(0, 1):
        progress(build_share, 1, "Memberi nomor halaman")
    for index in range(total):
        section = section_of[index]
        if section != current_section:
            current_section = section
            rule = numbering.get(section) or {}
            style = rule.get("style", "none")
            if style not in LABEL_STYLES:
                style = "none"
            if style == "none":
                page_label_ranges.append({"startpage": index, "prefix": SECTION_NAMES[section], "style": "", "firstpagenum": 1})
            else:
                number = last_number if truthy(rule.get("continue")) else int(rule.get("start", 1)) - 1
                page_label_ranges.append({"startpage": index, "prefix": "", "style": LABEL_STYLES[style], "firstpagenum": number + 1})

        if style == "none":
            labels.append(None)
            continue

        number += 1
        last_number = number
        is_first = index in first_pages
        if index + 1 in hidden or (is_first and first_rule == "hide"):
            labels.append(None)
            continue

        label = format_number(style, number)
        labels.append(label)
        if is_first and first_rule == "bottom_center":
            stamp_label(out[index], label, "bottom", "center", size, fontname, (0, 0, 0))
        else:
            stamp_label(out[index], label, vertical, align, size, fontname, (0, 0, 0))

    if truthy(options.get("page_labels"), True):
        out.set_page_labels(page_label_ranges)

    # Penanda (bookmark) per bagian dan per dokumen.
    if truthy(options.get("bookmarks"), True):
        toc = []
        grouped = truthy(options.get("grouping"), False)
        shown = set()
        for document in documents:
            level = 1
            if grouped:
                if document["section"] not in shown:
                    toc.append([1, SECTION_NAMES[document["section"]], document["start"]])
                    shown.add(document["section"])
                level = 2
            toc.append([level, document["title"][:120], document["start"]])
        out.set_toc(toc)

    title = str(ctx.opt("title", "")).strip()[:120]
    out.set_metadata({"title": title or "Paket tugas", "creator": "Konvertin", "producer": "Konvertin"})

    assembled = ctx.out("assembled.pdf")
    save_pdf(out, assembled, garbage=3)
    out.close()

    report = {
        "pages": total,
        "documents": documents,
        "sections": [],
    }
    for section in SECTION_ORDER:
        indexes = [index for index in range(total) if section_of[index] == section]
        if indexes:
            shown_labels = [labels[index] for index in indexes if labels[index]]
            report["sections"].append({
                "section": section,
                "pages": len(indexes),
                "first_label": shown_labels[0] if shown_labels else None,
                "last_label": shown_labels[-1] if shown_labels else None,
            })

    final = ctx.out("result.pdf")
    compression = json_option(options.get("compression"), {}) or {}
    if truthy(compression.get("enabled")):
        compression["_time_budget"] = options.get("_time_budget")
        with progress_scope(build_share + 0.05, 0.98):
            report["compression"] = smart_compress(assembled, final, compression, ctx)
        os.remove(assembled)
    else:
        os.replace(assembled, final)
    report["size"] = os.path.getsize(final)

    download = re.sub(r'[\x00-\x1f\\/:*?"<>|]+', " ", title).strip() or "paket-tugas"
    done(final, download + ".pdf", report)


# -------------------------------------------------------------- preflight

PAPER_LABELS = {"a4": "A4", "letter": "Letter", "legal": "Legal", "f4": "F4", "a5": "A5"}
BASE14 = {
    "Helvetica", "Helvetica-Bold", "Helvetica-Oblique", "Helvetica-BoldOblique",
    "Times-Roman", "Times-Bold", "Times-Italic", "Times-BoldItalic",
    "Courier", "Courier-Bold", "Courier-Oblique", "Courier-BoldOblique", "Symbol", "ZapfDingbats",
}
LEVEL_LABELS = {"ok": "Baik", "warn": "Perlu dicek", "info": "Info"}


def count_chars(text):
    """Jumlah karakter tanpa spasi, agar perbandingan tidak terpengaruh tata letak."""
    return len(re.sub(r"\s+", "", text or ""))


def number_text(value):
    return "{:,}".format(int(value)).replace(",", ".")


def size_text(size):
    if size >= 1024 * 1024:
        return ("%.1f MB" % (size / 1024 / 1024)).replace(".", ",")
    return "%d KB" % max(1, round(size / 1024))


def page_list_text(numbers, limit=24):
    """[2, 3, 4, 9] menjadi "2-4, 9" (nomor halaman berbasis satu)."""
    numbers = sorted(set(numbers))
    parts = []
    start = previous = None
    for number in numbers:
        if start is None:
            start = previous = number
        elif number == previous + 1:
            previous = number
        else:
            parts.append(str(start) if start == previous else "%d-%d" % (start, previous))
            start = previous = number
    if start is not None:
        parts.append(str(start) if start == previous else "%d-%d" % (start, previous))
    text = ", ".join(parts[:limit])
    return text + (" dan lainnya" if len(parts) > limit else "")


def paper_label(rect):
    short, long = sorted((rect.width, rect.height))
    for key, (width, height) in PAPER_SIZES.items():
        if abs(short - width) <= 4 and abs(long - height) <= 4:
            return PAPER_LABELS[key]
    return "%d x %d mm" % (round(short / MM), round(long / MM))


def pdf_date_text(value):
    match = re.match(r"D?:?(\d{4})(\d{2})?(\d{2})?", str(value or ""))
    if not match:
        return ""
    year, month, day = match.group(1), match.group(2) or "01", match.group(3) or "01"
    return "%s-%s-%s" % (day, month, year)


def ink_share(page):
    """Bagian piksel yang lebih gelap dari latar halaman (render 36 DPI, abu-abu)."""
    import numpy
    import pymupdf

    pixmap = page.get_pixmap(dpi=36, colorspace=pymupdf.csGRAY, alpha=False)
    pixels = numpy.frombuffer(pixmap.samples, dtype=numpy.uint8)
    if pixels.size == 0:
        return 0.0
    background = float(numpy.median(pixels))
    return float((pixels < background - 60).mean())


def page_is_blank(page):
    if page.get_text("text").strip():
        return False
    return ink_share(page) < 0.002


def page_fingerprint(page):
    """Sidik halaman untuk mendeteksi halaman ganda: teks bila ada, selain itu gambar kecil."""
    import hashlib

    import numpy
    import pymupdf

    size = (round(page.rect.width), round(page.rect.height))
    text = " ".join(page.get_text("text").split())
    if len(text) >= 30:
        return ("text", size, hashlib.sha1(text.encode("utf-8")).hexdigest())
    zoom = 32.0 / max(page.rect.width, 1)
    pixmap = page.get_pixmap(matrix=pymupdf.Matrix(zoom, zoom), colorspace=pymupdf.csGRAY, alpha=False)
    pixels = numpy.frombuffer(pixmap.samples, dtype=numpy.uint8) // 24
    return ("image", size, text, hashlib.sha1(pixels.tobytes()).hexdigest())


def scan_pages(doc):
    """Halaman kosong, halaman ganda, dan halaman gambar tanpa teks."""
    blank, duplicates, textless = [], [], []
    seen = {}
    for page in doc:
        progress(page.number, doc.page_count, "Memeriksa halaman %d dari %d" % (page.number + 1, doc.page_count))
        if page_is_blank(page):
            blank.append(page.number + 1)
            continue
        if not page.get_text("text").strip() and page.get_images():
            textless.append(page.number + 1)
        key = page_fingerprint(page)
        if key in seen:
            duplicates.append({"page": page.number + 1, "same_as": seen[key]})
        else:
            seen[key] = page.number + 1
    return blank, duplicates, textless


def font_names(doc):
    """(semua font, font non-standar yang tidak tertanam), tanpa awalan subset."""
    used, missing = set(), set()
    for page in doc:
        for font in page.get_fonts(full=False):
            ext, basefont = font[1], font[3]
            name = str(basefont or "").split("+", 1)[-1].strip()
            if not name:
                continue
            used.add(name)
            if ext in ("n/a", "") and name not in BASE14:
                missing.add(name)
    return sorted(used), sorted(missing)


def catalog_value(doc, key):
    kind, value = doc.xref_get_key(doc.pdf_catalog(), key)
    return None if kind == "null" else value


def structure_counts(doc):
    """Elemen struktur PDF bertag: gambar (dan yang punya teks alternatif), judul, tabel."""
    counts = {"figures": 0, "figures_alt": 0, "headings": 0, "tables": 0}
    headings = {"/H", "/H1", "/H2", "/H3", "/H4", "/H5", "/H6"}
    for xref in range(1, min(doc.xref_length(), 200000)):
        try:
            kind, value = doc.xref_get_key(xref, "S")
        except Exception:  # noqa: BLE001
            continue
        if kind != "name":
            continue
        if value == "/Figure":
            counts["figures"] += 1
            if doc.xref_get_key(xref, "Alt")[0] != "null":
                counts["figures_alt"] += 1
        elif value in headings:
            counts["headings"] += 1
        elif value == "/Table":
            counts["tables"] += 1
    return counts


def der_length(data):
    if len(data) < 2 or data[0] != 0x30:
        return len(data)
    first = data[1]
    if first < 0x80:
        return 2 + first
    width = first & 0x7F
    return 2 + width + int.from_bytes(data[2:2 + width], "big")


def certificate_details(der):
    """Nama penanda tangan, penerbit, dan masa berlaku dari sertifikat di dalam tanda tangan."""
    try:
        from cryptography.hazmat.primitives.serialization import pkcs7
        from cryptography.x509.oid import NameOID
    except Exception:  # noqa: BLE001
        return None

    try:
        certificates = pkcs7.load_der_pkcs7_certificates(der[:der_length(der)])
    except Exception:  # noqa: BLE001
        return None
    if not certificates:
        return None

    issuers = {cert.issuer.rfc4514_string() for cert in certificates}
    signer = next((cert for cert in certificates if cert.subject.rfc4514_string() not in issuers or len(certificates) == 1), certificates[0])

    def common_name(name):
        values = name.get_attributes_for_oid(NameOID.COMMON_NAME)
        return values[0].value if values else name.rfc4514_string()

    before = getattr(signer, "not_valid_before_utc", None) or signer.not_valid_before
    after = getattr(signer, "not_valid_after_utc", None) or signer.not_valid_after
    return {
        "subject": str(common_name(signer.subject))[:120],
        "issuer": str(common_name(signer.issuer))[:120],
        "valid_from": before.strftime("%d-%m-%Y"),
        "valid_to": after.strftime("%d-%m-%Y"),
    }


def signature_fields(doc, raw):
    import pymupdf

    found = []
    for page in doc:
        for widget in page.widgets():
            if widget.field_type != pymupdf.PDF_WIDGET_TYPE_SIGNATURE:
                continue
            entry = {"field": str(widget.field_name or "")[:80], "page": page.number + 1, "signed": False}
            kind, value = doc.xref_get_key(widget.xref, "V")
            if kind == "xref":
                signature = int(value.split()[0])
                numbers = [int(n) for n in re.findall(r"-?\d+", doc.xref_get_key(signature, "ByteRange")[1] or "")]
                entry["signed"] = True
                entry["time"] = pdf_date_text(doc.xref_get_key(signature, "M")[1])
                entry["covers_whole_file"] = len(numbers) == 4 and numbers[2] + numbers[3] == len(raw)
                if len(numbers) == 4 and 0 < numbers[1] < numbers[2] <= len(raw):
                    hex_text = raw[numbers[1]:numbers[2]].strip().lstrip(b"<").rstrip(b">")
                    try:
                        entry["certificate"] = certificate_details(bytes.fromhex(hex_text.decode("ascii")))
                    except ValueError:
                        entry["certificate"] = None
            found.append(entry)
    return found


def check(group, level, title, detail=""):
    return {"group": group, "level": level, "title": title, "detail": detail}


def preflight_checks(doc, path, blank, duplicates, textless, target_bytes):
    import pymupdf

    checks = []
    total = doc.page_count

    # Halaman
    sizes = {}
    landscape, rotated = [], []
    for page in doc:
        sizes.setdefault(paper_label(page.rect), []).append(page.number + 1)
        if page.rect.width > page.rect.height:
            landscape.append(page.number + 1)
        if page.rotation:
            rotated.append(page.number + 1)
    if len(sizes) == 1:
        checks.append(check("Halaman", "ok", "Semua %d halaman berukuran %s" % (total, next(iter(sizes)))))
    else:
        main = max(sizes, key=lambda key: len(sizes[key]))
        others = "; ".join("%s: halaman %s" % (label, page_list_text(pages)) for label, pages in sizes.items() if label != main)
        checks.append(check("Halaman", "warn", "Ukuran kertas campuran", "Sebagian besar %s. Lainnya %s." % (main, others)))
    if landscape:
        checks.append(check("Halaman", "info", "%d halaman mendatar" % len(landscape), "Halaman %s." % page_list_text(landscape)))
    if rotated:
        checks.append(check("Halaman", "info", "%d halaman memakai pengaturan rotasi" % len(rotated), "Halaman %s. Tampilannya sudah benar di pembaca PDF." % page_list_text(rotated)))
    checks.append(check("Halaman", "warn", "Halaman kosong: %s" % page_list_text(blank), "Biasanya ikut terbawa saat menggabungkan atau memindai.") if blank else check("Halaman", "ok", "Tidak ada halaman kosong"))
    if duplicates:
        pairs = ", ".join("%d sama dengan %d" % (item["page"], item["same_as"]) for item in duplicates[:12])
        checks.append(check("Halaman", "warn", "%d halaman ganda" % len(duplicates), "Halaman %s%s. Periksa pratinjau sebelum menghapusnya." % (pairs, " dan lainnya" if len(duplicates) > 12 else "")))
    else:
        checks.append(check("Halaman", "ok", "Tidak ada halaman ganda"))

    # Isi
    if textless:
        checks.append(check("Isi", "warn", "%d halaman berupa gambar tanpa teks" % len(textless), "Halaman %s. Teksnya tidak bisa dicari atau disalin; jalankan OCR PDF." % page_list_text(textless)))
    else:
        checks.append(check("Isi", "ok", "Semua halaman berisi teks yang bisa dicari"))
    used, missing = font_names(doc)
    if missing:
        checks.append(check("Isi", "warn", "Font tidak tertanam: %s" % ", ".join(missing[:6]), "Di komputer tanpa font ini, tampilan bisa berubah. Ekspor ulang dari aplikasi asal dengan opsi sematkan font."))
    elif used:
        checks.append(check("Isi", "ok", "Semua font tertanam atau font standar PDF"))
    widgets = [widget for page in doc for widget in page.widgets()]
    form_fields = [widget for widget in widgets if widget.field_type not in (pymupdf.PDF_WIDGET_TYPE_SIGNATURE, pymupdf.PDF_WIDGET_TYPE_BUTTON)]
    if form_fields:
        checks.append(check("Isi", "info", "Formulir interaktif: %d field" % len(form_fields), "Bisa diisi langsung lewat PDF Forms."))
    else:
        checks.append(check("Isi", "info", "Tidak ada field formulir interaktif", "Untuk mengisi PDF ini, tambahkan field teks atau checkbox lewat PDF Forms."))
    toc = doc.get_toc(simple=True)
    if toc:
        checks.append(check("Isi", "ok", "%d bookmark" % len(toc)))
    elif total >= 10:
        checks.append(check("Isi", "info", "Belum ada bookmark", "Dokumen %d halaman lebih mudah dinavigasi dengan bookmark." % total))

    # Ukuran
    size = os.path.getsize(path)
    if target_bytes:
        if size <= target_bytes:
            checks.append(check("Ukuran", "ok", "%s, di bawah batas %s" % (size_text(size), size_text(target_bytes))))
        else:
            checks.append(check("Ukuran", "warn", "%s, melebihi batas %s" % (size_text(size), size_text(target_bytes)), "Gunakan Smart Compression dengan target %s." % size_text(target_bytes)))
    else:
        checks.append(check("Ukuran", "info", "Ukuran berkas %s" % size_text(size), "Rata-rata %s per halaman." % size_text(size / total)))

    # Aksesibilitas (laporan dasar, bukan pernyataan kepatuhan)
    metadata = doc.metadata or {}
    title = (metadata.get("title") or "").strip()
    checks.append(check("Aksesibilitas", "ok", "Judul dokumen: %s" % title[:80]) if title else check("Aksesibilitas", "warn", "Judul dokumen kosong", "Pembaca layar dan tab peramban menampilkan nama berkas sebagai gantinya."))
    language = (catalog_value(doc, "Lang") or "").strip("()")
    checks.append(check("Aksesibilitas", "ok", "Bahasa dokumen: %s" % language[:20]) if language else check("Aksesibilitas", "warn", "Bahasa dokumen tidak ditentukan", "Pembaca layar bisa salah melafalkan teks."))
    tagged = catalog_value(doc, "StructTreeRoot") is not None
    if tagged:
        counts = structure_counts(doc)
        checks.append(check("Aksesibilitas", "ok", "PDF bertag (memiliki struktur)"))
        if counts["figures"]:
            missing_alt = counts["figures"] - counts["figures_alt"]
            checks.append(check("Aksesibilitas", "warn" if missing_alt else "ok", "%d dari %d gambar punya teks alternatif" % (counts["figures_alt"], counts["figures"])))
        checks.append(check("Aksesibilitas", "ok", "%d judul bagian bertag" % counts["headings"]) if counts["headings"] else check("Aksesibilitas", "warn" if total >= 3 else "info", "Tidak ada judul bagian bertag", "Pakai gaya Heading di Word sebelum ekspor."))
        if counts["tables"]:
            checks.append(check("Aksesibilitas", "info", "%d tabel bertag" % counts["tables"]))
    else:
        checks.append(check("Aksesibilitas", "warn", "PDF tidak bertag", "Teks alternatif gambar, judul bagian, dan struktur tabel tidak dapat dibaca pembaca layar. Ekspor ulang dari Word dengan opsi tag struktur dokumen."))
    unlabeled = [widget for widget in form_fields if not (widget.field_label or "").strip()]
    if unlabeled:
        checks.append(check("Aksesibilitas", "warn", "%d field formulir tanpa label" % len(unlabeled), "Pembaca layar hanya menyebut nama teknis field."))
    checks.append(check("Aksesibilitas", "info", "Urutan baca dan struktur tabel tidak diperiksa", "Keduanya perlu diperiksa manual. Laporan ini bukan pernyataan kepatuhan PDF/UA atau WCAG."))

    # Tanda tangan digital
    with open(path, "rb") as handle:
        raw = handle.read()
    signatures = signature_fields(doc, raw)
    signed = [item for item in signatures if item["signed"]]
    if not signatures:
        checks.append(check("Tanda tangan digital", "info", "Tidak ada tanda tangan digital", "Tanda tangan berupa gambar bukan tanda tangan digital dan tidak dapat diverifikasi."))
    for item in signatures:
        if not item["signed"]:
            checks.append(check("Tanda tangan digital", "info", "Field tanda tangan kosong: %s" % item["field"], "Halaman %d, belum ditandatangani." % item["page"]))
            continue
        certificate = item.get("certificate")
        detail = "Dibuat %s." % item["time"] if item.get("time") else ""
        if certificate:
            detail = "Sertifikat diterbitkan %s, berlaku %s s.d. %s. %s" % (certificate["issuer"], certificate["valid_from"], certificate["valid_to"], detail)
        name = certificate["subject"] if certificate else item["field"]
        checks.append(check("Tanda tangan digital", "info", "Tanda tangan digital: %s" % name, detail.strip()))
        if item["covers_whole_file"]:
            checks.append(check("Tanda tangan digital", "ok", "Tidak ada perubahan setelah tanda tangan %s" % name))
        else:
            checks.append(check("Tanda tangan digital", "warn", "Ada perubahan setelah tanda tangan %s" % name, "Bisa berupa tanda tangan berikutnya atau suntingan. Periksa di pembaca PDF yang memvalidasi tanda tangan."))
    if signed:
        checks.append(check("Tanda tangan digital", "info", "Keabsahan sertifikat tidak diverifikasi", "Konvertin hanya membaca data tanda tangan. Validasi kriptografis dan kepercayaan penerbit perlu dilakukan di Adobe Acrobat Reader atau layanan resmi penerbit sertifikat."))

    # Metadata
    author = (metadata.get("author") or "").strip()
    creator = (metadata.get("creator") or metadata.get("producer") or "").strip()
    created = pdf_date_text(metadata.get("creationDate"))
    facts = []
    if author:
        facts.append("Penulis: %s" % author[:60])
    if creator:
        facts.append("Dibuat dengan: %s" % creator[:60])
    if created:
        facts.append("Tanggal: %s" % created)
    if facts:
        checks.append(check("Metadata", "info", "; ".join(facts), "Metadata ikut terkirim bersama berkas. Redact PDF dapat membersihkannya."))
    else:
        checks.append(check("Metadata", "info", "Metadata kosong"))

    return checks, signatures


def write_report_pdf(target, title, subtitle, checks, note):
    import html as html_text

    import pymupdf

    css = (
        "* {font-family: sans-serif;} h1 {font-size: 18px; margin: 0 0 4px 0;} "
        "p.sub {font-size: 10px; color: #555555; margin: 0 0 14px 0;} "
        "h2 {font-size: 12.5px; margin: 14px 0 6px 0; color: #0060c0;} "
        "p.item {font-size: 10px; margin: 0 0 6px 0;} span.ok {color: #15803d; font-weight: bold;} "
        "span.warn {color: #b45309; font-weight: bold;} span.info {color: #475569; font-weight: bold;} "
        "span.detail {color: #444444;} p.note {font-size: 9px; color: #555555; margin-top: 16px;}"
    )
    parts = ["<h1>%s</h1><p class='sub'>%s</p>" % (html_text.escape(title), html_text.escape(subtitle))]
    group = None
    for item in checks:
        if item["group"] != group:
            group = item["group"]
            parts.append("<h2>%s</h2>" % html_text.escape(group))
        detail = " <span class='detail'>%s</span>" % html_text.escape(item["detail"]) if item["detail"] else ""
        parts.append("<p class='item'><span class='%s'>[%s]</span> %s.%s</p>" % (
            item["level"], LEVEL_LABELS[item["level"]], html_text.escape(item["title"]), detail))
    parts.append("<p class='note'>%s</p>" % html_text.escape(note))

    story = pymupdf.Story(html="".join(parts), user_css=css)
    writer = pymupdf.DocumentWriter(target)
    mediabox = pymupdf.paper_rect("a4")
    where = mediabox + (48, 52, -48, -52)
    more = True
    while more:
        device = writer.begin_page(mediabox)
        more, _ = story.place(where)
        story.draw(device)
        writer.end_page()
    writer.close()
    return target


def op_preflight(ctx):
    doc = open_pdf(ctx.inputs[0], ctx)
    truthy = (True, "1", "true", 1, "on")
    remove_blank = ctx.opt("remove_blank") in truthy
    remove_duplicates = ctx.opt("remove_duplicates") in truthy
    pdf_output = ctx.opt("_pdf_output") in truthy or remove_blank or remove_duplicates

    target_bytes = None
    if ctx.opt("target_value"):
        target_bytes = int(float(ctx.opt("target_value")) * (1024 if ctx.opt("target_unit", "MB") == "KB" else 1024 * 1024))

    with progress_scope(0, 0.8):
        blank, duplicates, textless = scan_pages(doc)
    progress(0.82, 1, "Memeriksa font, aksesibilitas, dan tanda tangan")
    checks, signatures = preflight_checks(doc, ctx.inputs[0], blank, duplicates, textless, target_bytes)

    remove = set()
    if remove_blank:
        remove.update(blank)
    if remove_duplicates:
        remove.update(item["page"] for item in duplicates)
    if remove and len(remove) >= doc.page_count:
        # Tidak pernah menghasilkan PDF tanpa halaman.
        remove = set()

    report = {
        "pages": doc.page_count,
        "size": os.path.getsize(ctx.inputs[0]),
        "checks": checks,
        "blank_pages": blank,
        "duplicate_pages": duplicates,
        "scanned_pages": textless,
        "signatures": [{key: value for key, value in item.items() if key != "field"} for item in signatures],
        "removed": sorted(remove),
        "cleaned": pdf_output,
        "warnings": sum(1 for item in checks if item["level"] == "warn"),
    }

    if pdf_output:
        progress(0.9, 1, "Menyimpan PDF yang sudah dibersihkan" if remove else "Menyimpan PDF")
        if remove:
            doc.select([index for index in range(doc.page_count) if index + 1 not in remove])
        report["pages_after"] = doc.page_count
        done(save_pdf(doc, ctx.out("result.pdf")), ctx.name + ("-bersih.pdf" if remove else ".pdf"), report)
        return

    progress(0.9, 1, "Menyusun laporan")
    note = ("Laporan ini hasil pemeriksaan otomatis Konvertin. Bukan pernyataan kepatuhan standar aksesibilitas "
            "(PDF/UA, WCAG) dan tidak memvalidasi keabsahan tanda tangan digital.")
    subtitle = "%s.pdf - %d halaman - %s" % (ctx.name, doc.page_count, size_text(report["size"]))
    target = write_report_pdf(ctx.out("result.pdf"), "Laporan Preflight Check", subtitle, checks, note)
    done(target, ctx.name + "-laporan-preflight.pdf", report)


# ------------------------------------------------- pemeriksaan hasil konversi


def zip_texts(path, pattern):
    """Teks di dalam elemen <w:t> atau <a:t> pada berkas XML Office yang cocok dengan pola."""
    import html as html_text

    total = 0
    with zipfile.ZipFile(path) as bundle:
        for name in bundle.namelist():
            if re.match(pattern, name):
                xml = bundle.read(name).decode("utf-8", "ignore")
                total += sum(count_chars(html_text.unescape(text)) for text in re.findall(r"<[wa]:t(?:\s[^>]*)?>([^<]*)</[wa]:t>", xml))
    return total


def zip_count(path, pattern):
    with zipfile.ZipFile(path) as bundle:
        return sum(1 for name in bundle.namelist() if re.match(pattern, name))


def markdown_plain(markdown):
    """Markdown tanpa penanda sintaks (judul, tebal, garis tabel) untuk menghitung isi teks."""
    text = re.sub(r"^\s*\|?[\s:|-]*-{3,}[\s:|-]*$", "", markdown, flags=re.M)
    text = re.sub(r"^#{1,6}\s", "", text, flags=re.M)
    return text.replace("**", "").replace("|", "")


def source_profile(doc, table_pages=30):
    chars, images, textless, tables = 0, set(), [], 0
    for page in doc:
        text = page.get_text("text")
        chars += count_chars(text)
        page_images = page.get_images(full=True)
        images.update(image[0] for image in page_images)
        if not text.strip() and page_images:
            textless.append(page.number + 1)
    for page in list(doc)[:table_pages]:
        try:
            tables += len(page.find_tables().tables)
        except Exception:  # noqa: BLE001
            pass
    used, missing = font_names(doc)
    return {
        "pages": doc.page_count, "chars": chars, "images": len(images), "tables": tables,
        "tables_scanned": min(doc.page_count, table_pages), "textless": textless, "fonts": used, "fonts_missing": missing,
    }


def text_check(source_chars, output_chars, what):
    if source_chars == 0:
        return check("Teks", "warn", "PDF tidak memiliki lapisan teks", "Hasil hanya berisi gambar halaman. Jalankan OCR PDF dulu agar teks bisa disunting.")
    ratio = output_chars / float(source_chars)
    numbers = "%s dari %s karakter" % (number_text(min(output_chars, source_chars)), number_text(source_chars))
    if ratio >= 0.97 and ratio <= 1.1:
        return check("Teks", "ok", "Teks terbawa lengkap", "%s ada di %s." % (numbers, what))
    if ratio > 1.1:
        return check("Teks", "info", "Teks hasil lebih panjang dari aslinya", "%d%% dari jumlah karakter asli; mungkin ada teks ganda." % round(ratio * 100))
    return check("Teks", "warn", "Sebagian teks tidak terbawa", "Hanya %d%% karakter (%s) ada di %s. Bandingkan dengan PDF asli." % (round(ratio * 100), numbers, what))


def common_quality_checks(source):
    checks = []
    if source["textless"]:
        checks.append(check("Isi", "warn", "%d halaman berupa gambar tanpa teks" % len(source["textless"]), "Halaman %s. Teksnya tidak ikut terkonversi; jalankan OCR PDF dulu." % page_list_text(source["textless"])))
    if source["fonts_missing"]:
        checks.append(check("Font", "warn", "Font tidak tertanam di PDF: %s" % ", ".join(source["fonts_missing"][:5]), "Font pengganti akan dipakai sehingga tampilan bisa bergeser."))
    return checks


def inspect_conversion(kind, doc, target, extra=None):
    """Pemeriksaan terukur atas hasil konversi: dibandingkan dengan PDF asli, tanpa skor."""
    progress(0.95, 1, "Memeriksa hasil konversi")
    source = source_profile(doc)
    checks = []
    tables_note = "dari %d halaman pertama" % source["tables_scanned"] if source["tables_scanned"] < source["pages"] else "di PDF"

    if kind == "docx":
        output_chars = zip_texts(target, r"word/(document|header\d*|footer\d*|footnotes|endnotes)\.xml$")
        with zipfile.ZipFile(target) as bundle:
            document = bundle.read("word/document.xml").decode("utf-8", "ignore")
        output_tables = len(re.findall(r"<w:tbl>", document))
        output_images = zip_count(target, r"word/media/")
        checks.append(text_check(source["chars"], output_chars, "dokumen Word"))
        if source["images"]:
            checks.append(check("Gambar", "ok" if output_images >= source["images"] else "warn", "%d dari %d gambar terbawa" % (min(output_images, source["images"]), source["images"]), "" if output_images >= source["images"] else "Gambar yang hilang mungkin digabung ke latar atau tidak dapat dibaca."))
        if source["tables"]:
            checks.append(check("Tabel", "ok" if output_tables >= source["tables"] else "warn", "%d tabel terdeteksi %s, %d menjadi tabel Word" % (source["tables"], tables_note, output_tables), "" if output_tables >= source["tables"] else "Tabel lain mungkin menjadi teks biasa. Periksa dan rapikan di Word."))
        checks.append(check("Halaman", "info", "PDF %d halaman" % source["pages"], "Jumlah halaman di Word bisa berbeda karena teks mengalir ulang."))
        if source["fonts"]:
            checks.append(check("Font", "info", "Font asli: %s" % ", ".join(source["fonts"][:5]), "Bila tidak terpasang di komputer Anda, Word memakai font pengganti."))
    elif kind == "pptx":
        slides = zip_count(target, r"ppt/slides/slide\d+\.xml$")
        notes = zip_texts(target, r"ppt/notesSlides/notesSlide\d+\.xml$")
        checks.append(check("Halaman", "ok" if slides == source["pages"] else "warn", "%d halaman menjadi %d slide" % (source["pages"], slides)))
        checks.append(check("Isi", "info", "Slide berupa gambar halaman", "Tampilan sama persis dengan PDF, tetapi teks di slide tidak dapat disunting. Teks asli disimpan di catatan pembicara."))
        checks.append(text_check(source["chars"], notes, "catatan pembicara"))
    elif kind == "xlsx":
        from openpyxl import load_workbook

        workbook = load_workbook(target, read_only=True)
        cells = filled = chars = 0
        for sheet in workbook.worksheets:
            for row in sheet.iter_rows(values_only=True):
                for value in row:
                    cells += 1
                    if value not in (None, ""):
                        filled += 1
                        chars += count_chars(str(value))
        sheets = len(workbook.worksheets)
        workbook.close()
        checks.append(check("Tabel", "ok", "%d tabel menjadi %d sheet" % (sheets, sheets)))
        if cells:
            empty = 1 - filled / float(cells)
            checks.append(check("Tabel", "warn" if empty > 0.4 else "ok", "%d%% sel kosong" % round(empty * 100), "Sel gabungan di PDF menjadi sel kosong; periksa sebelum menghitung." if empty > 0.4 else ""))
        if source["chars"]:
            share = chars / float(source["chars"])
            checks.append(check("Teks", "info", "%d%% teks PDF ada di dalam tabel" % min(100, round(share * 100)), "Paragraf dan judul di luar tabel tidak ikut ke Excel."))
    elif kind == "md":
        with open(target, encoding="utf-8") as handle:
            markdown = handle.read()
        checks.append(text_check(source["chars"], count_chars(markdown_plain(markdown)), "Markdown"))
        headings = len(re.findall(r"(?m)^#{1,6}\s", markdown))
        checks.append(check("Struktur", "ok" if headings else "info", "%d judul bagian dikenali" % headings if headings else "Tidak ada judul bagian yang dikenali", "" if headings else "Struktur bab mungkin perlu ditandai manual."))
        md_tables = len(re.findall(r"(?m)^\|?\s*:?-{3,}", markdown))
        if source["tables"]:
            checks.append(check("Tabel", "ok" if md_tables >= source["tables"] else "warn", "%d tabel terdeteksi %s, %d menjadi tabel Markdown" % (source["tables"], tables_note, md_tables)))
        if source["images"]:
            checks.append(check("Gambar", "info", "%d gambar tidak disertakan" % source["images"], "Markdown hanya berisi teks."))
        method = (extra or {}).get("method")
        if method and method != "layout":
            checks.append(check("Struktur", "info", "Dipakai ekstraksi %s" % ("klasik" if method == "classic" else "sederhana"), "Analisis tata letak tidak berhasil untuk PDF ini, jadi strukturnya lebih sederhana."))

    checks.extend(common_quality_checks(source))
    return {"checks": checks, "warnings": sum(1 for item in checks if item["level"] == "warn")}


def safe_inspection(kind, doc, target, extra=None):
    """Pemeriksaan tidak boleh menggagalkan konversi yang sudah berhasil."""
    try:
        return inspect_conversion(kind, doc, target, extra)
    except Exception as error:  # noqa: BLE001
        sys.stderr.write("inspection skipped %s\n" % type(error).__name__)
        return None


def op_bookmarks(ctx):
    """Menyimpan daftar bookmark (outline) baru ke PDF.

    outline: [{level, title, page, source}] dengan page berbasis satu. source
    menunjuk indeks bookmark asli; bila halamannya tidak diubah, tujuan aslinya
    (posisi dan zoom di halaman) dipertahankan.
    """
    doc = open_pdf(ctx.inputs[0], ctx)
    items = ctx.opt("outline", [])
    if isinstance(items, str):
        items = json.loads(items)
    if not isinstance(items, list):
        raise EngineError(EXIT_GENERIC, "Outline tidak valid")

    original = doc.get_toc(simple=False)
    toc = []
    previous = 0
    for item in items:
        level = int(item.get("level", 1))
        page = int(item.get("page", 0))
        title = str(item.get("title", "")).strip()[:200]
        if not title or level < 1 or level > previous + 1:
            raise EngineError(EXIT_GENERIC, "Hierarki bookmark tidak valid")
        if not 1 <= page <= doc.page_count:
            raise EngineError(EXIT_INVALID_PAGES, "Halaman bookmark di luar jangkauan")
        entry = [level, title, page]
        source = item.get("source")
        if isinstance(source, int) and 0 <= source < len(original) and original[source][2] == page and len(original[source]) > 3:
            entry.append(original[source][3])
        toc.append(entry)
        previous = level

    unchanged = [entry[:3] for entry in toc] == [entry[:3] for entry in original] and all(
        isinstance(item.get("source"), int) and item.get("source") == index for index, item in enumerate(items)
    )
    if not unchanged:
        doc.set_toc(toc)

    done(save_pdf(doc, ctx.out("result.pdf")), ctx.name + "-bookmark.pdf", {
        "bookmarks": len(toc),
        "levels": max([entry[0] for entry in toc] or [0]),
        "unchanged": unchanged,
        "pages": doc.page_count,
    })


WORKFLOW_STEPS = {
    "merge", "rotate", "crop", "compress", "repair", "ocr", "pagenumbers", "watermark", "protect", "unlock", "preflight",
}


def op_workflow(ctx):
    steps = ctx.opt("steps", [])
    if isinstance(steps, str):
        steps = json.loads(steps)
    if not steps:
        raise EngineError(EXIT_GENERIC, "Workflow kosong")

    current_inputs = list(ctx.inputs)
    report_steps = []
    final = None

    for number, step in enumerate(steps, start=1):
        op = step.get("op")
        if op not in WORKFLOW_STEPS:
            raise EngineError(EXIT_GENERIC, "Langkah tidak dikenal")
        progress(number - 1, len(steps), "Menjalankan langkah %d dari %d" % (number, len(steps)))

        step_dir = os.path.join(ctx.out_dir, "step-%02d" % number)
        os.makedirs(step_dir, exist_ok=True)
        options = dict(step.get("options") or {})
        options["_max_pages"] = ctx.max_pages
        if op == "preflight":
            options["_pdf_output"] = True
        args = argparse.Namespace(out_dir=step_dir, name=ctx.name, inputs=current_inputs if op == "merge" else current_inputs[:1], aux=ctx.args.aux)
        step_ctx = Context(args, options)

        captured = io.StringIO()
        original_stdout = sys.stdout
        sys.stdout = captured
        try:
            with progress_scope((number - 1) / len(steps), number / len(steps)):
                OPERATIONS[op](step_ctx)
        except EngineError as error:
            sys.stdout = original_stdout
            report_steps.append({"op": op, "status": "failed", "code": error.code})
            print(json.dumps({"workflow_failed": number, "steps": report_steps}), file=sys.stderr)
            raise
        finally:
            sys.stdout = original_stdout

        result = json.loads(captured.getvalue().strip().splitlines()[-1])
        report_steps.append({"op": op, "status": "done", "report": result.get("report", {})})
        current_inputs = [result["file"]]
        final = result

    done(final["file"], ctx.name + "-workflow.pdf", {"steps": report_steps})


OPERATIONS = {
    "check": op_check,
    "check-ocr": op_check_ocr,
    "jpg2pdf": op_jpg2pdf,
    "pdf2jpg": op_pdf2jpg,
    "pdf2docx": op_pdf2docx,
    "pdf2pptx": op_pdf2pptx,
    "pdf2xlsx": op_pdf2xlsx,
    "pdf2md": op_pdf2md,
    "merge": op_merge,
    "split": op_split,
    "organize": op_organize,
    "rotate": op_rotate,
    "crop": op_crop,
    "compress": op_compress,
    "repair": op_repair,
    "ocr": op_ocr,
    "pagenumbers": op_pagenumbers,
    "watermark": op_watermark,
    "overlay": op_overlay,
    "protect": op_protect,
    "unlock": op_unlock,
    "redact": op_redact,
    "compare": op_compare,
    "forms": op_forms,
    "scan": op_scan,
    "info": op_info,
    "extract-blocks": op_extract_blocks,
    "apply-translation": op_apply_translation,
    "workflow": op_workflow,
    "pack": op_pack,
    "preflight": op_preflight,
    "bookmarks": op_bookmarks,
}


def read_options():
    if sys.stdin is None or sys.stdin.isatty():
        return {}
    raw = sys.stdin.read()
    if not raw.strip():
        return {}
    try:
        options = json.loads(raw)
    except ValueError:
        raise EngineError(EXIT_GENERIC, "Opsi tidak valid")
    return options if isinstance(options, dict) else {}


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("operation", choices=sorted(OPERATIONS))
    parser.add_argument("--out-dir", default=".")
    parser.add_argument("--name", default="hasil")
    parser.add_argument("--aux", action="append")
    parser.add_argument("inputs", nargs="*")
    args = parser.parse_args()

    try:
        ctx = Context(args, read_options())
        OPERATIONS[args.operation](ctx)
    except EngineError as error:
        # Pesan teknis singkat saja; tidak memuat isi berkas maupun opsi.
        sys.stderr.write("%s\n" % error)
        return error.code
    except Exception as error:  # noqa: BLE001
        sys.stderr.write("%s\n" % type(error).__name__)
        return EXIT_GENERIC

    return 0


if __name__ == "__main__":
    sys.exit(main())
