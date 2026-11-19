import json
import math
import os
import sys
from datetime import datetime, timedelta, timezone
from pathlib import Path
from tempfile import NamedTemporaryFile


DATASET = "IFREMER-ATL-SST-L4-NRT-OBS_FULL_TIME_SERIE"
LATITUDE = 37.1032529
LONGITUDE = -8.4681189
MAX_AGE = timedelta(days=4)
WEBROOT = Path(__file__).resolve().parents[1]


def latest_sea_temperature(dataset, now):
    temperatures = dataset["analysed_sst"]
    units = temperatures.attrs.get("units", "").strip().lower()
    if units not in {"k", "kelvin"}:
        raise ValueError("Unexpected ODYSSEA temperature unit")

    latitude = next(name for name in ("latitude", "lat") if name in temperatures.dims)
    longitude = next(name for name in ("longitude", "lon") if name in temperatures.dims)
    grid = temperatures.transpose("time", latitude, longitude).values
    mask = dataset["mask"].transpose("time", latitude, longitude).values
    longitude_weight = math.cos(math.radians(LATITUDE))
    cells = sorted(
        (
            ((lat - LATITUDE) ** 2 + ((lon - LONGITUDE) * longitude_weight) ** 2, y, x)
            for y, lat in enumerate(temperatures[latitude].values)
            for x, lon in enumerate(temperatures[longitude].values)
        )
    )
    dates = temperatures["time"].values
    for i in sorted(range(len(dates)), key=dates.__getitem__, reverse=True):
        stamp = datetime.fromisoformat(str(dates[i])).replace(tzinfo=timezone.utc)
        if not now - MAX_AGE <= stamp <= now:
            continue
        for _, y, x in cells:
            celsius = float(grid[i, y, x]) - 273.15
            # ODYSSEA mask 1 is open sea. Land, lake, ice and missing cells are excluded.
            if mask[i, y, x] == 1 and -3 <= celsius <= 45:
                return {"temperature": round(celsius, 1), "time": stamp.isoformat()}
    raise ValueError("No recent, valid sea surface temperature in the coastal grid")


def write_snapshot(path, snapshot):
    temporary_path = None
    try:
        with NamedTemporaryFile(mode="w", encoding="utf-8", dir=path.parent, delete=False) as temporary:
            temporary_path = Path(temporary.name)
            json.dump(snapshot, temporary, allow_nan=False)
            temporary.write("\n")
        temporary_path.replace(path)
    finally:
        if temporary_path is not None:
            temporary_path.unlink(missing_ok=True)


def main():
    import copernicusmarine

    storage = Path(os.environ.get("CASA_WEATHER_STORAGE_DIR") or WEBROOT.parent / "storage").resolve()
    if storage == WEBROOT or WEBROOT in storage.parents:
        raise ValueError("Set CASA_WEATHER_STORAGE_DIR to a directory outside the webroot")
    now = datetime.now(timezone.utc)
    with copernicusmarine.open_dataset(
        dataset_id=DATASET,
        variables=["analysed_sst", "mask"],
        minimum_longitude=LONGITUDE - 0.045,
        maximum_longitude=LONGITUDE + 0.045,
        minimum_latitude=LATITUDE - 0.06,
        maximum_latitude=LATITUDE,
        start_datetime=(now - MAX_AGE).strftime("%Y-%m-%dT%H:%M:%S"),
        end_datetime=now.strftime("%Y-%m-%dT%H:%M:%S"),
        coordinates_selection_method="inside",
    ) as dataset:
        snapshot = latest_sea_temperature(dataset, now)
    write_snapshot(storage / "sea-temperature.json", snapshot)


if __name__ == "__main__":
    try:
        main()
    except Exception as error:
        print(f"Copernicus update failed ({type(error).__name__}). Existing data retained.", file=sys.stderr)
        sys.exit(1)
