from __future__ import annotations

from collections.abc import Mapping
from typing import Any, TypeVar, BinaryIO, TextIO, TYPE_CHECKING, Generator

from attrs import define as _attrs_define
from attrs import field as _attrs_field

from ..types import UNSET, Unset

from ..types import UNSET, Unset
from typing import cast
import datetime






T = TypeVar("T", bound="HealthResponse")



@_attrs_define
class HealthResponse:
    """ 
        Attributes:
            status (str | Unset):
            service (str | Unset):
            env (str | Unset):
            db (bool | Unset):
            time (datetime.datetime | Unset):
     """

    status: str | Unset = UNSET
    service: str | Unset = UNSET
    env: str | Unset = UNSET
    db: bool | Unset = UNSET
    time: datetime.datetime | Unset = UNSET
    additional_properties: dict[str, Any] = _attrs_field(init=False, factory=dict)





    def to_dict(self) -> dict[str, Any]:
        status = self.status

        service = self.service

        env = self.env

        db = self.db

        time: str | Unset = UNSET
        if not isinstance(self.time, Unset):
            time = self.time.isoformat()


        field_dict: dict[str, Any] = {}
        field_dict.update(self.additional_properties)
        field_dict.update({
        })
        if status is not UNSET:
            field_dict["status"] = status
        if service is not UNSET:
            field_dict["service"] = service
        if env is not UNSET:
            field_dict["env"] = env
        if db is not UNSET:
            field_dict["db"] = db
        if time is not UNSET:
            field_dict["time"] = time

        return field_dict



    @classmethod
    def from_dict(cls: type[T], src_dict: Mapping[str, Any]) -> T:
        d = dict(src_dict)
        status = d.pop("status", UNSET)

        service = d.pop("service", UNSET)

        env = d.pop("env", UNSET)

        db = d.pop("db", UNSET)

        _time = d.pop("time", UNSET)
        time: datetime.datetime | Unset
        if isinstance(_time,  Unset):
            time = UNSET
        else:
            time = datetime.datetime.fromisoformat(_time)




        health_response = cls(
            status=status,
            service=service,
            env=env,
            db=db,
            time=time,
        )


        health_response.additional_properties = d
        return health_response

    @property
    def additional_keys(self) -> list[str]:
        return list(self.additional_properties.keys())

    def __getitem__(self, key: str) -> Any:
        return self.additional_properties[key]

    def __setitem__(self, key: str, value: Any) -> None:
        self.additional_properties[key] = value

    def __delitem__(self, key: str) -> None:
        del self.additional_properties[key]

    def __contains__(self, key: str) -> bool:
        return key in self.additional_properties
