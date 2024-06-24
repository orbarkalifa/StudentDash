import React, { useState, useEffect, useRef } from 'react';
import { Card, Row, Col, Container, Image } from 'react-bootstrap';
import { Link } from 'react-router-dom';
import './StudentCard.css';
import { backspaceSVGDataUrl, expandContentSVGDataUrl } from './constants';

function adjustFontSizeForElements() {
    const elements = document.querySelectorAll('.lecturer-info p');
    elements.forEach(el => {
        let fontSize = 16; // Start with a base font size in pixels
        el.style.fontSize = `${fontSize}px`;
        while (el.scrollWidth > el.offsetWidth || el.scrollHeight > el.offsetHeight) {
            fontSize--;
            el.style.fontSize = `${fontSize}px`;
            if (fontSize < 8) {
                break;
            } // Avoid too small font size
        }
    });
}

function CourseCard({ course, isVisible }) {
    const cardRef = useRef(null);

    useEffect(() => {
        // Adjust font size when the component is rendered and when window resizes
        adjustFontSizeForElements();
        const handleResize = () => adjustFontSizeForElements();
        window.addEventListener('resize', handleResize);
        return () => window.removeEventListener('resize', handleResize);
    }, []);

    useEffect(() => {
        if (isVisible) {
            cardRef.current.classList.add('show');
        }
    }, [isVisible]);

    const getDaysDifference = (date1, date2) => {
        const oneDay = 24 * 60 * 60 * 1000;
        return Math.round(Math.abs((date1 - date2) / oneDay));
    };

    const getDaysUntilMoedA = () => {
        const moedAExam = course.exams.find(exam => exam.exam_type === 'Moed A');
        return moedAExam ? getDaysDifference(new Date(moedAExam.exam_date * 1000), new Date()) : 'N/A';
    };

    return (
        <Col ref={cardRef} className="card-container show">
            <Card className="subject-card">
                <Card.Header className="card-header d-flex justify-content-between align-items-center">
                    <Link to={`/details/${course.id}`}>
                        <Image src={expandContentSVGDataUrl} alt="expand content" width="30" className="hover-effect-image" />
                    </Link>
                    <h3 style={{ color: "black", fontWeight: "bolder" }}>{course.fullname}</h3>

                    <a href={course.url} target="_blank" rel="noopener noreferrer">
                        <Image className="keyboard_backspace" src={backspaceSVGDataUrl} alt="course site" width={70} height={40} />
                    </a>
                </Card.Header>
                <Card.Body>
                    <Row>
                        <Col className="d-flex justify-content-between card-column">
                            <h2>{course.tasks.length}</h2>
                            <span>משימות ממתינות</span>
                        </Col>
                        <Col className="d-flex justify-content-between card-column">
                            <h2>{course.zoomRecords.filter(record => record.status === 'unwatched').length}</h2>
                            <span>הקלטות טרם נצפו</span>
                        </Col>
                    </Row>
                    <Row>
                        <Col className="d-flex justify-content-between card-column">
                            <h2>{getDaysUntilMoedA()}</h2>
                            <span>ימים למועד א</span>
                        </Col>
                        <Col className="d-flex justify-content-between card-column">
                            <div>
                                <span>מרצה</span>
                                <Image src="/local/studentdash/frontend/dashboard/build/contact_mail.png" alt="" />
                            </div>
                            <div className="lecturer-info">
                                <p className="lecturer-name">{course.lecturer || 'ד"ר יועש חסידים'}</p>
                                <p className="lecturer-email" title={course.lectureremail || 'yoash@sapir.edu.co.il'}>
                                    {course.lectureremail || 'yoash@sapir.edu.co.il'}
                                </p>
                            </div>
                        </Col>
                    </Row>
                </Card.Body>
            </Card>
        </Col>
    );
}

function SubjectCard({ studentInfo }) {
    const [courses, setCourses] = useState([]);
    const [loading, setLoading] = useState(true);
    const [visibleCards, setVisibleCards] = useState(new Set());
    useEffect(() => {
        setCourses(studentInfo.courses);
    }, [studentInfo.courses]);

    useEffect(() => {
        adjustFontSizeForElements();
        // Add resize event listener to re-check when window size changes
        window.addEventListener('resize', adjustFontSizeForElements);

        return () => {
            // Cleanup resize listener on component unmount
            window.removeEventListener('resize', adjustFontSizeForElements);
        };
    }, []);
    useEffect(() => {
        setCourses(studentInfo.courses);
        setLoading(false);
    }, [studentInfo.courses]);

    useEffect(() => {
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    setVisibleCards(prevVisibleCards => new Set(prevVisibleCards).add(entry.target));
                    observer.unobserve(entry.target);
                }
            });
        }, { threshold: 0.1 });

        const cardElements = document.querySelectorAll('.card-container');
        cardElements.forEach(card => observer.observe(card));

        return () => {
            cardElements.forEach(card => observer.unobserve(card));
        };
    }, [courses]);

    return (
        <Container fluid style={{ padding: '20px', maxWidth: '1200px' }}>
            <Row xs={1} md={2} className="g-4">
                {courses.map((course, idx) => (
                    <CourseCard key={idx} course={course} isVisible={visibleCards.has(course.id)} />
                ))}
            </Row>
        </Container>
    );
}

export default SubjectCard;
